<?php

namespace App\Http\Controllers;

use App\Services\TicketStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TicketAttachmentController extends Controller
{
    public function store(Request $request, int $ticket, TicketStore $store): RedirectResponse|JsonResponse
    {
        // Authorize before writing any bytes to private storage.
        $store->call('TicketShow', [$request->user()->id, $ticket]);
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png,txt', 'extensions:pdf,jpg,jpeg,png,txt'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $filename = Str::limit(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $file->getClientOriginalName()) ?? 'attachment', 200, '');
        $path = $file->storeAs("tickets/$ticket", Str::uuid()->toString(), 'attachments');
        abort_if($path === false, 503, 'Attachment could not be saved. Please try again.');
        try {
            $store->call('TicketAttachmentAdd', [
                $request->user()->id, $ticket, $filename, $path, $file->getMimeType(), $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('attachments')->delete($path);
            throw $exception;
        }

        return $request->routeIs('api.*') ? response()->json(['message' => 'Attachment uploaded.'], 201) : to_route('tickets.show', ['ticket' => $ticket]);
    }

    public function download(Request $request, int $ticket, int $attachment, TicketStore $store): StreamedResponse
    {
        $rows = $store->call('TicketAttachmentGet', [$request->user()->id, $ticket, $attachment]);
        abort_if($rows === [], 404);
        $record = $rows[0];
        $disk = Storage::disk('attachments');
        abort_unless($disk->exists($record->path), 404);

        return $disk->download($record->path, $record->filename, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
