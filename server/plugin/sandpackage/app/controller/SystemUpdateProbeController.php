<?php
declare(strict_types=1);
namespace plugin\sandpackage\app\controller;

use support\Request;
use support\Response;

/** Private loopback health protocol, authenticated by a short-lived random challenge. */
final class SystemUpdateProbeController
{
    public function probe(Request $request): Response
    {
        $file = base_path() . '/runtime/system-update/health-probe.json';
        $token = $request->header('x-sand-update-probe', '');
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!in_array($request->getRemoteIp(), ['127.0.0.1', '::1'], true) || !is_array($data) || ($data['expires_at'] ?? 0) < time()
            || !is_string($token) || !hash_equals($data['token'] ?? '', $token)) return new Response(404, [], 'Not Found');
        return json(['token' => $token, 'versions' => ['supdger/sand-core' => config('plugin.sandadmin.app.version'), 'supdger/sand-package' => config('plugin.sandpackage.app.version')]]);
    }
}
