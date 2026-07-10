<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures JSON request bodies are reliably available via $request->input() / validate()
 * for EVERY route (not just api/* paths).
 *
 * Background: Laravel's PreventRequestForgery (CSRF) middleware reads the request input
 * bag before the controller runs. For JSON requests this triggers json_decode of
 * php://input. Due to php://input being readable only once under `artisan serve`,
 * non-api/* JSON POSTs could end up with an empty input bag in the controller while the
 * raw body was still present via getContent(). This middleware parses the JSON body early
 * (caching it) and merges it into the request so all routes behave like api/* routes.
 */
class MergeJsonPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson()) {
            // Force php://input to be read + cached once, up front.
            $content = (string) $request->getContent();

            if ($content !== '') {
                $data = json_decode($content, true);

                if (is_array($data)) {
                    // 合并到 JSON bag，使 $request->input() / validate() 都能读到。
                    // 注意：不能用 empty($request->all()) 作为合并前置条件——
                    // 一旦请求带 query 参数或其它字段，合法 JSON body 会被漏合，
                    // 导致 validate('message' => 'required') 误报「字段缺失」(422)。
                    // 直接 add() 到 json bag 是幂等的（同键覆盖），不会重复合。
                    $request->json()->add($data);
                }
            }
        }

        return $next($request);
    }
}
