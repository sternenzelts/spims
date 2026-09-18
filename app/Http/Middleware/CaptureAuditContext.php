<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class CaptureAuditContext
{
    /**
     * Carry the client address and browser into every audit entry written while handling the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Context::addHidden([
            AuditLogger::IP_ADDRESS => $request->ip(),
            AuditLogger::USER_AGENT => $request->userAgent(),
        ]);

        return $next($request);
    }
}
