<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * Where uploads live. Private on purpose: files are served through
     * download() so ownership is checked, instead of being fetchable by
     * anyone who has the URL. Configurable so production can point at a
     * bucket without a code change.
     */
    private static function disk(): string
    {
        return config('filesystems.uploads', 'local');
    }

    /**
     * Uploads made before that change sat on the public disk. Reads fall back
     * to it so existing rows keep working.
     */
    private const LEGACY_DISK = 'public';

    private const MAX_KILOBYTES = 20480;

    private const ALLOWED_MIMES = 'jpeg,png,jpg,gif,svg,doc,docx,xls,xlsx,pdf,txt,csv,zip,html,css,js';

    public function index()
    {
        $files = Auth::user()->files()->latest()->get();

        return view('files.index', compact('files'));
    }

    public function create()
    {
        return view('files.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => 'required|file|max:'.self::MAX_KILOBYTES.'|mimes:'.self::ALLOWED_MIMES,
            'type' => 'required|string|in:project,docs,txt,code,image',
        ]);

        $upload = $request->file('file');

        Auth::user()->files()->create([
            'name' => $request->name,
            'path' => $upload->store('uploads', self::disk()),
            'type' => $request->type,
            'original_name' => $upload->getClientOriginalName(),
            'mime_type' => $upload->getClientMimeType(),
            'size' => $upload->getSize(),
        ]);

        return redirect()->route('files.index')->with('success', 'File uploaded successfully.');
    }

    public function show(File $file)
    {
        $this->authorizeOwner($file);

        return view('files.show', compact('file'));
    }

    /**
     * Stream the file to its owner.
     *
     * The download link used to be a plain Storage::url(), which needs the
     * public symlink (never created on deploy, so every download 404'd) and
     * would have made every upload readable by anyone holding the URL.
     */
    public function download(File $file, Request $request): StreamedResponse
    {
        $this->authorizeOwner($file);

        $disk = $this->diskHolding($file);

        // The row can outlive the file itself — say so plainly instead of
        // throwing a 500.
        abort_if($disk === null, 404, 'This file is no longer stored on the server.');

        $name = $file->original_name ?: ($file->name ?: 'file');

        // Images and PDFs are nicer opened in the browser; everything else
        // downloads.
        $inline = $request->boolean('inline') && $file->isViewableInline();

        return Storage::disk($disk)->download($file->path, $name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($name).'"',
        ]);
    }

    public function edit(File $file)
    {
        $this->authorizeOwner($file);

        return view('files.edit', compact('file'));
    }

    public function update(Request $request, File $file)
    {
        $this->authorizeOwner($file);
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => 'nullable|file|max:'.self::MAX_KILOBYTES.'|mimes:'.self::ALLOWED_MIMES,
            'type' => 'required|string|in:project,docs,txt,code,image',
        ]);

        $data = $request->only(['name', 'type']);

        if ($request->hasFile('file')) {
            $this->deleteStoredFile($file);

            $upload = $request->file('file');
            $data['path'] = $upload->store('uploads', self::disk());
            $data['original_name'] = $upload->getClientOriginalName();
            $data['mime_type'] = $upload->getClientMimeType();
            $data['size'] = $upload->getSize();
        }

        $file->update($data);

        return redirect()->route('files.index')->with('success', 'File updated successfully.');
    }

    public function destroy(File $file)
    {
        $this->authorizeOwner($file);
        $this->deleteStoredFile($file);
        $file->delete();

        return redirect()->route('files.index')->with('success', 'File deleted successfully.');
    }

    /**
     * Which disk actually holds this file, or null if it is gone.
     */
    private function diskHolding(File $file): ?string
    {
        foreach ([self::disk(), self::LEGACY_DISK] as $disk) {
            if ($file->path && Storage::disk($disk)->exists($file->path)) {
                return $disk;
            }
        }

        return null;
    }

    private function deleteStoredFile(File $file): void
    {
        $disk = $this->diskHolding($file);

        if ($disk !== null) {
            Storage::disk($disk)->delete($file->path);
        }
    }

    /**
     * Ensure the authenticated user owns the given file.
     */
    private function authorizeOwner(File $file): void
    {
        abort_unless($file->user_id === Auth::id(), 403);
    }
}
