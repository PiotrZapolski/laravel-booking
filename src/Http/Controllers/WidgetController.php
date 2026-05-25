<?php

namespace Zapol\Booking\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Zapol\Booking\Services\BookingTokenSigner;

class WidgetController extends Controller
{
    public function script(): Response
    {
        $path = __DIR__ . '/../../../resources/dist/widget.js';
        $js = is_file($path) ? (string) file_get_contents($path) : '/* booking widget missing — run vendor:publish --tag=booking-assets */';

        return response($js, 200, [
            'Content-Type'  => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function embed(Request $request): Response
    {
        $slug = (string) $request->query('event_type', '');
        return response(view('booking::embed', ['slug' => $slug])->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function reschedule(string $token, BookingTokenSigner $signer): Response
    {
        try {
            $payload = $signer->verify($token);
        } catch (\Throwable $e) {
            return response(view('booking::reschedule-error', ['message' => $e->getMessage()])->render(), 400);
        }

        return response(view('booking::reschedule', [
            'token'     => $token,
            'eventType' => $payload['et'],
        ])->render());
    }
}
