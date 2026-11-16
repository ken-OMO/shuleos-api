<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\StudentElection\StudentElectionClassroomAccess;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class StudentElectionStationBoundary
{
    public function handle(Request $request, Closure $next, string $mode = 'device')
    {
        app(StudentElectionClassroomAccess::class)->enabled();
        abort_unless($request->isSecure() || app()->environment('testing'), 403, 'HTTPS is required.');
        $id = $mode === 'teacher' ? (string) $request->route('election')
            : ($request->is('api/election-station/activate') ? (string) $request->input('station_id')
                : explode('.', (string) $request->cookie('shuleos_election_device', ''))[0]);
        abort_unless(Str::isUuid($id), 401);
        $table = $mode === 'teacher' ? 'student_elections' : 'student_election_stations';
        $query = DB::table($table)->where('id', $id);
        if ($mode === 'teacher') {
            $tenant = (string) $request->attributes->get('tenant_school_id', '');
            abort_unless(Str::isUuid($tenant) && $request->user() !== null
                && (string) $request->user()->getAttribute('school_id') === $tenant, 403);
            $query->where('school_id', $tenant);
        }
        $school = $query->value('school_id');
        abort_unless($school, $mode === 'teacher' ? 404 : 401);
        $networks = config('student_election_stations.school_networks.'.$school, []);
        abort_unless(is_array($networks) && $networks !== [] && IpUtils::checkIp((string) $request->ip(), $networks), 403, 'Trusted school network required.');
        $origin = rtrim((string) config('student_election_stations.origin'), '/');
        if ($mode !== 'teacher') {
            abort_unless($origin !== '' && hash_equals($origin, (string) $request->header('Origin'))
                && $request->header('X-Election-Station') === '1', 403, 'Trusted-origin station request required.');
            $credential = (string) $request->cookie('shuleos_election_device', '');
            $bucket = preg_match('/^[a-f0-9-]{36}\.[a-f0-9]{64}$/D', $credential) ? hash('sha256', $credential) : 'ip:'.$request->ip();
            $key = 'station-http:'.$bucket;
            abort_if(RateLimiter::tooManyAttempts($key, 120), 429);
            RateLimiter::hit($key, 60);
        }
        try {
            $response = $next($request);
        } catch (ValidationException $error) {
            $response = response()->json(['success' => false, 'message' => 'Station request rejected.', 'errors' => $error->errors()], 422);
        } catch (AuthorizationException) {
            $response = response()->json(['success' => false, 'message' => 'Station access is unavailable.'], 403);
        } catch (AuthenticationException) {
            $response = response()->json(['success' => false, 'message' => 'Station is unavailable.'], 401);
        } catch (ModelNotFoundException|RecordNotFoundException) {
            $response = response()->json(['success' => false, 'message' => 'Station request is unavailable.'], 404);
        } catch (HttpExceptionInterface $error) {
            $response = response()->json(['success' => false, 'message' => 'Station request is unavailable.'], $error->getStatusCode(), $error->getHeaders());
        } catch (\Throwable) {
            // Do not log choices, admission input, cookie secrets, SQL bindings
            // or the original exception chain from a sensitive request.
            report(new \RuntimeException('Classroom election operation failed.'));
            $response = response()->json(['success' => false, 'message' => 'Station operation failed.'], 500);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
