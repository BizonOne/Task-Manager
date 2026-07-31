<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Task;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function index()
    {
        $files = Auth::user()->files()->with('task')->latest()->get();

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
            'file' => Uploads::rule(),
            'type' => 'required|string|in:project,docs,txt,code,image',
        ]);

        Uploads::store(
            $request->file('file'),
            Auth::user(),
            name: $request->name,
            type: $request->type,
        );

        return redirect()->route('files.index')->with('success', 'File uploaded successfully.');
    }

    /**
     * Attach files to a task, from the task page.
     */
    public function attach(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();
        abort_unless($task->isAccessibleBy($user), 403);

        $request->validate([
            'files' => 'required|array|max:10',
            'files.*' => Uploads::rule(),
        ]);

        $stored = collect($request->file('files'))
            ->map(fn ($upload) => Uploads::store($upload, $user, task: $task))
            ->map(fn (File $file) => $this->toJson($file));

        return response()->json(['success' => true, 'files' => $stored]);
    }

    public function show(File $file)
    {
        $this->authorizeView($file);

        return view('files.show', compact('file'));
    }

    /**
     * Stream the file to anyone allowed to see it.
     *
     * The download link used to be a plain Storage::url(), which needs the
     * public symlink (never created on deploy, so every download 404'd) and
     * would have made every upload readable by anyone holding the URL.
     */
    public function download(File $file, Request $request): StreamedResponse
    {
        $this->authorizeView($file);

        $disk = Uploads::diskHolding($file);

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
        $this->authorizeManage($file);

        return view('files.edit', compact('file'));
    }

    public function update(Request $request, File $file)
    {
        $this->authorizeManage($file);
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => Uploads::rule(required: false),
            'type' => 'required|string|in:project,docs,txt,code,image',
        ]);

        $data = $request->only(['name', 'type']);

        if ($request->hasFile('file')) {
            Uploads::deleteStored($file);

            $upload = $request->file('file');
            $data['path'] = $upload->store('uploads', Uploads::disk());
            $data['original_name'] = $upload->getClientOriginalName();
            $data['mime_type'] = $upload->getClientMimeType();
            $data['size'] = $upload->getSize();
        }

        $file->update($data);

        return redirect()->route('files.index')->with('success', 'File updated successfully.');
    }

    public function destroy(Request $request, File $file)
    {
        $this->authorizeManage($file);
        Uploads::deleteStored($file);
        $file->delete();

        // Deleting from a task page should stay on the task page.
        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('files.index')->with('success', 'File deleted successfully.');
    }

    /**
     * What the task page needs to render a freshly uploaded attachment.
     *
     * @return array<string, mixed>
     */
    public function toJson(File $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name ?: $file->name,
            'size' => $file->readable_size,
            'icon' => $file->icon,
            'inline' => $file->isViewableInline(),
            'url' => route('files.download', $file),
            'uploader' => $file->user?->name ?? Auth::user()->name,
        ];
    }

    private function authorizeView(File $file): void
    {
        abort_unless($file->isAccessibleBy(Auth::user()), 403);
    }

    private function authorizeManage(File $file): void
    {
        abort_unless($file->isManageableBy(Auth::user()), 403);
    }
}
