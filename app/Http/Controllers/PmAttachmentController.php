<?php

namespace App\Http\Controllers;

use App\Models\PmWorkItem;
use App\Services\PM\Providers\JiraProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PmAttachmentController extends Controller
{
    /**
     * Stream or download a Jira task attachment through an authenticated proxy.
     */
    public function show(Request $request, string $workItemKey, string $attachmentId, JiraProvider $jiraProvider): Response
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }

        $workItem = PmWorkItem::with('connection')
            ->where('external_item_key', $workItemKey)
            ->orWhere('id', $workItemKey)
            ->first();

        if (! $workItem) {
            abort(404, 'Task not found.');
        }

        // Authorization: Verify user is allowed to access the client for this work item
        if (! $user->isSuperAdmin()) {
            $allowedClientIds = $user->getAssignedClientIds();
            if (! in_array($workItem->client_id, $allowedClientIds, true)) {
                abort(403, 'Unauthorized access to client task attachments.');
            }
        }

        $connection = $workItem->connection;
        if (! $connection) {
            abort(404, 'PM connection not found.');
        }

        $isDownload = $request->boolean('download');
        $isThumbnail = $request->boolean('thumbnail');

        // Look up attachment metadata in attachments_json if available
        $attachmentMeta = null;
        if (is_array($workItem->attachments_json)) {
            foreach ($workItem->attachments_json as $att) {
                if ((string) ($att['id'] ?? '') === (string) $attachmentId || ($att['filename'] ?? '') === $attachmentId) {
                    $attachmentMeta = $att;
                    break;
                }
            }
        }

        // If not found in attachments_json, refresh from Jira API to check for recently added attachments
        if (! $attachmentMeta) {
            try {
                $issue = $jiraProvider->getWorkItem($connection, $workItem->external_item_key, $user);
                $rawAttachments = $issue['fields']['attachment'] ?? [];
                if (is_array($rawAttachments)) {
                    $refreshedAttachments = [];
                    foreach ($rawAttachments as $att) {
                        $normalized = [
                            'id'            => (string) ($att['id'] ?? ''),
                            'filename'      => $att['filename'] ?? 'attachment',
                            'size'          => (int) ($att['size'] ?? 0),
                            'mime_type'     => $att['mimeType'] ?? ($att['mime_type'] ?? 'application/octet-stream'),
                            'url'           => $att['content'] ?? ($att['url'] ?? null),
                            'thumbnail_url' => $att['thumbnail'] ?? ($att['thumbnail_url'] ?? null),
                            'author_name'   => $att['author']['displayName'] ?? ($att['author_name'] ?? null),
                            'created_at'    => $att['created'] ?? ($att['created_at'] ?? null),
                        ];
                        $refreshedAttachments[] = $normalized;
                        if ((string) ($normalized['id'] ?? '') === (string) $attachmentId || ($normalized['filename'] ?? '') === $attachmentId) {
                            $attachmentMeta = $normalized;
                        }
                    }
                    $workItem->update(['attachments_json' => $refreshedAttachments]);
                }
            } catch (\Throwable $e) {
                Log::warning("PmAttachmentController: Failed refreshing attachments for {$workItem->external_item_key}: {$e->getMessage()}");
            }
        }

        $resolvedAttachmentId = $attachmentMeta['id'] ?? $attachmentId;
        $filename = $attachmentMeta['filename'] ?? 'attachment';
        $mimeType = $attachmentMeta['mime_type'] ?? 'application/octet-stream';

        // Check local disk cache
        $cacheDir = storage_path('app/jira-attachments');
        if (! File::exists($cacheDir)) {
            File::makeDirectory($cacheDir, 0755, true);
        }

        $cacheSuffix = $isThumbnail ? '_thumb' : '';
        $cacheFile = "{$cacheDir}/{$workItem->id}_{$resolvedAttachmentId}{$cacheSuffix}";

        if (File::exists($cacheFile)) {
            $content = File::get($cacheFile);
        } else {
            // Fetch from Jira
            $result = $jiraProvider->getAttachmentContent($connection, $resolvedAttachmentId, $isThumbnail, $user);
            if (! $result || empty($result['body'])) {
                // If thumbnail failed, fallback to full image
                if ($isThumbnail) {
                    $result = $jiraProvider->getAttachmentContent($connection, $resolvedAttachmentId, false, $user);
                }
            }

            if (! $result || empty($result['body'])) {
                abort(404, 'Attachment media could not be retrieved from Jira.');
            }

            $content = $result['body'];
            if (! empty($result['content_type'])) {
                $mimeType = $result['content_type'];
            }

            // Cache binary to local storage
            File::put($cacheFile, $content);
        }

        $disposition = $isDownload ? 'attachment' : 'inline';

        return response($content, 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            'Content-Length'      => strlen($content),
            'Cache-Control'       => 'private, max-age=86400',
        ]);
    }
}
