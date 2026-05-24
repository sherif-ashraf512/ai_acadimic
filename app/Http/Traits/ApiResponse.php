<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
        // ══════════════════════════════════════════════════════════════
    //  Success Responses
    // ══════════════════════════════════════════════════════════════

    /**
     * 200 OK — Generic success with optional data.
     */
    protected function success(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message ?? __('api.success'),
            'data'    => $data,
        ], $code);
    }

    /**
     * 201 Created — Resource was successfully created.
     */
    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->success($data, $message ?? __('api.created'), 201);
    }

    /**
     * 200 OK — Paginated collection.
     * Wraps paginator into a consistent envelope with meta + links.
     */
    protected function paginated(LengthAwarePaginator $paginator, string $key = 'items', array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => array_merge([
                $key   => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                ],
                'links' => [
                    'first' => $paginator->url(1),
                    'last'  => $paginator->url($paginator->lastPage()),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                ],
            ], $extra),
        ]);
    }

    /**
     * 204 No Content — Action succeeded, nothing to return.
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    // ══════════════════════════════════════════════════════════════
    //  Error Responses
    // ══════════════════════════════════════════════════════════════

    /**
     * 400 Bad Request — Invalid input that isn't a validation error.
     */
    protected function badRequest(?string $message = null, mixed $errors = null): JsonResponse
    {
        return $this->error($message ?? __('api.bad_request'), 400, $errors);
    }

    /**
     * 401 Unauthorized — Not authenticated.
     */
    protected function unauthorized(?string $message = null): JsonResponse
    {
        return $this->error($message ?? __('auth.unauthenticated'), 401);
    }

    /**
     * 403 Forbidden — Authenticated but not authorized.
     */
    protected function forbidden(?string $message = null): JsonResponse
    {
        return $this->error($message ?? __('api.forbidden'), 403);
    }

    /**
     * 404 Not Found.
     */
    protected function notFound(?string $message = null): JsonResponse
    {
        return $this->error($message ?? __('api.resource_not_found'), 404);
    }

    /**
     * 409 Conflict — Business rule violation (e.g. state machine conflict).
     */
    protected function conflict(string $message, mixed $errors = null): JsonResponse
    {
        return $this->error($message, 409, $errors);
    }

    /**
     * 422 Unprocessable Entity — Validation errors.
     */
    protected function validationError(mixed $errors, ?string $message = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message ?? __('api.validation_failed'),
            'errors'  => $errors,
        ], 422);
    }

    /**
     * 500 Internal Server Error.
     */
    protected function serverError(?string $message = null): JsonResponse
    {
        return $this->error($message ?? __('api.unexpected_error'), 500);
    }

    // ══════════════════════════════════════════════════════════════
    //  Core builder
    // ══════════════════════════════════════════════════════════════

    /**
     * Base error envelope used by all error helpers.
     */
    protected function error(string $message, int $code, mixed $errors = null): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $message,
        ];

        if (! is_null($errors)) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $code);
    }
}
