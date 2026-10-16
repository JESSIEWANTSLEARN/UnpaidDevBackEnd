<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Public newsletter signup using the existing Brevo integration.
 * Subscribers are stored in Brevo Contacts, so no new database table is needed.
 */
class NewsletterController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
        ]);

        $email = strtolower(trim($validated['email']));
        $apiKey = (string) config('services.brevo.key');

        if ($apiKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Email updates are temporarily unavailable.',
            ], 503);
        }

        $senderEmail = (string) config('mail.from.address');
        $senderName = (string) config(
            'mail.from.name',
            'WalangBrownout'
        );

        if ($senderEmail === '') {
            return response()->json([
                'success' => false,
                'message' => 'Email sender is not configured.',
            ], 503);
        }

        try {
            $contact = [
                'email' => $email,
                'updateEnabled' => true,
            ];

            $listId = (int) config(
                'services.brevo.newsletter_list_id',
                0
            );

            if ($listId > 0) {
                $contact['listIds'] = [$listId];
            }

            Http::acceptJson()
                ->withHeaders([
                    'api-key' => $apiKey,
                ])
                ->post(
                    'https://api.brevo.com/v3/contacts',
                    $contact
                )
                ->throw();

            $html = '
                <div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;padding:32px;color:#0f172a;">
                    <div style="font-size:12px;font-weight:700;letter-spacing:.12em;color:#1687ff;">
                        WALANG BROWN OUT
                    </div>
                    <h1 style="margin:12px 0 14px;font-size:30px;">
                        You are subscribed.
                    </h1>
                    <p style="font-size:16px;line-height:1.65;color:#475569;">
                        This email confirms that ' .
                        e($email) .
                        ' joined the Walang Brownout email updates list.
                    </p>
                    <p style="font-size:14px;line-height:1.6;color:#64748b;">
                        Product and store announcements can now be sent through the configured Brevo newsletter contacts.
                    </p>
                </div>
            ';

            Http::acceptJson()
                ->withHeaders([
                    'api-key' => $apiKey,
                ])
                ->post(
                    'https://api.brevo.com/v3/smtp/email',
                    [
                        'sender' => [
                            'name' => $senderName,
                            'email' => $senderEmail,
                        ],
                        'to' => [
                            [
                                'email' => $email,
                                'name' => $email,
                            ],
                        ],
                        'subject' =>
                            'Walang Brownout email updates confirmation',
                        'htmlContent' => $html,
                    ]
                )
                ->throw();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'We could not complete the email subscription. Please try again.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Subscribed successfully. Check your email for confirmation.',
        ]);
    }
}
