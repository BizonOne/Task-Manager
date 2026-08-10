<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\File;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Open an attachment the way a person would.
 *
 * Text comes back as text and images as images. Formats an agent cannot
 * usefully read over this wire — a .docx, an .mov — are named for what
 * they are rather than dumped as base64 noise.
 */
class ReadAttachment extends Tool
{
    /**
     * Textual formats whose mime type does not say text/*.
     */
    private const TEXTUAL = ['application/json', 'application/xml', 'application/javascript',
        'application/x-yaml', 'application/sql', 'application/csv'];

    private const MAX_BYTES = 2 * 1024 * 1024;

    protected string $description = 'Read one attachment by id (ids come from get_task). Text files '
        .'come back as text, images as images; other formats return their metadata only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'attachment_id' => $schema->integer()
                ->description('The attachment id, from get_task.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $file = File::find((int) $request->get('attachment_id'));

        // One answer for "gone" and "not yours", so ids cannot be probed.
        if (! $file || ! $file->isAccessibleBy($user)) {
            return Response::error('No attachment matches that id, or it is not visible to you.');
        }

        $disk = Uploads::diskHolding($file);

        if ($disk === null) {
            return Response::error('This file is no longer stored on the server.');
        }

        if ((int) $file->size > self::MAX_BYTES) {
            return Response::error(sprintf('"%s" is %s — too large to read over this connection. Ask a person to summarise it.',
                $file->original_name ?? $file->name, $file->readable_size));
        }

        $mime = (string) $file->mime_type;
        $contents = Storage::disk($disk)->get($file->path);

        if ($file->isImage()) {
            return Response::image(base64_encode($contents), $mime);
        }

        if (Str::startsWith($mime, 'text/') || in_array($mime, self::TEXTUAL, true)) {
            return Response::text($contents);
        }

        return Response::error(sprintf('"%s" is %s (%s) — a format this connection cannot render. '
            .'It stays available to people on the task page.',
            $file->original_name ?? $file->name, $mime, $file->readable_size));
    }
}
