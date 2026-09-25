<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Localized framework messages for the API (locale set by SetLocale middleware).
        $this->renderable(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => __('messages.generic.too_many_requests')], 429, $e->getHeaders());
            }
        });
    }

    protected function invalidJson($request, \Illuminate\Validation\ValidationException $exception)
    {
        return response()->json([
            'message' => __('messages.generic.validation_failed'),
            'errors'  => $exception->errors(),
        ], $exception->status);
    }

    protected function unauthenticated($request, \Illuminate\Auth\AuthenticationException $exception)
    {
        return $request->expectsJson() || $request->is('api/*')
            ? response()->json(['message' => __('messages.auth.unauthenticated')], 401)
            : redirect()->guest($exception->redirectTo() ?? route('login'));
    }
}
