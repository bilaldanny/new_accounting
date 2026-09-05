<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\Order;
use App\Models\Program;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentCourse;
use App\Services\Timeout\TimeoutCalculator;
use App\Services\Timeout\TimeoutCalculatorException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;

class HomeController extends Controller
{
    public function check(Request $request)
    {
        try {
            return response()->json(TimeoutCalculator::getSecondsLeft($request));
        } catch (TimeoutCalculatorException $e) {
            // Do not surface internal timeout exceptions to the UI.
            // Inactivity widget already handles 401/419 by redirecting to login.
            return response()->json([
                'message' => $e->getMessage(),
            ], 401);
        }
    }

    public function fetchcodes(Request $request)
    {

        $code = null;

        $code = fetchCodes($request->type);

        return response()->json(['code' => $code]);
    }

    public function check_smtp()
    {
        $setting = Setting::query()->first();
        $mailer = config('mail.mailers.smtp', []);

        $host = $setting?->smtp_host ?: ($mailer['host'] ?? null);
        $port = $setting?->smtp_port ?: ($mailer['port'] ?? null);
        $username = $setting?->smtp_username ?: ($mailer['username'] ?? null);
        $password = $setting?->smtp_password ?: ($mailer['password'] ?? null);
        $encryption = $setting?->smtp_encryption ?: ($mailer['scheme'] ?? null);

        if ($encryption === 'null' || $encryption === '') {
            $encryption = null;
        }

        if (! filled($host) || ! filled($username) || $password === null || $password === '') {
            return response()->json([
                'status' => 'alert',
                'message' => 'SMTP settings are not configured.',
            ], 400);
        }

        try {
            $dsn = sprintf(
                'smtp://%s:%s@%s:%d',
                urlencode((string) $username),
                urlencode((string) $password),
                $host,
                (int) $port
            );

            if (in_array($encryption, ['tls', 'ssl'], true)) {
                $dsn .= '?encryption='.$encryption;
            }

            $transport = Transport::fromDsn($dsn);
            $transport->start();

            return response()->json([
                'status' => 'success',
                'message' => 'SMTP connection successful!',
            ]);
        } catch (TransportExceptionInterface $e) {
            return response()->json([
                'status' => 'alert',
                'message' => 'SMTP transport error: '.$e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'alert',
                'message' => 'General error: '.$e->getMessage(),
            ], 400);
        }
    }

    public function fetchtotal()
    {
        $totals = Cache::remember('dashboard.admin_totals', now()->addMinutes(5), function () {
            $total_courses = Course::where('is_active', 1)->count();
            $total_programs = Program::where('is_active', 1)->count();
            $total_students = Student::whereHas('user', function ($q) {
                $q->where('is_active', 1);
            })->count();
            $total_sales = Order::where('payment_status', true)->sum('total_amount');
            $total_pending_sales = Cart::pendingSalesTotal();
            $total_orders = Order::count();
            $new_orders = Order::whereDate('created_at', '>=', Carbon::today()->subDays(6))->count();

            $total_completed_courses = StudentCourse::query()
                ->whereNotNull('course_id')
                ->where('completed', 1)
                ->count();

            $total_completed_programs = StudentCourse::query()
                ->whereNull('course_id')
                ->whereNotNull('program_id')
                ->where('completed', 1)
                ->count();

            $total_chats = Conversation::query()->count();
            $resolved_chats = Conversation::query()
                ->where('status', ConversationStatus::Closed)
                ->count();
            $open_chats = Conversation::query()
                ->whereIn('status', [ConversationStatus::Open, ConversationStatus::Pending])
                ->count();

            return [
                'total_courses' => $total_courses,
                'total_programs' => $total_programs,
                'total_students' => $total_students,
                'total_sales' => $total_sales,
                'total_pending_sales' => $total_pending_sales,
                'total_orders' => $total_orders,
                'new_orders' => $new_orders,
                'total_completed_courses' => $total_completed_courses,
                'total_completed_programs' => $total_completed_programs,
                'total_chats' => $total_chats,
                'resolved_chats' => $resolved_chats,
                'open_chats' => $open_chats,
            ];
        });

        return response()->json($totals);
    }

    public function fetchstudenttotal()
    {
        $studentId = auth()->user()?->student?->id;

        if ($studentId === null) {
            return response()->json([
                'completed_courses' => 0,
                'enrolled_courses' => 0,
                'completed_programs' => 0,
                'enrolled_programs' => 0,
            ]);
        }

        $totals = Cache::remember("dashboard.student_totals:{$studentId}", now()->addMinutes(2), function () use ($studentId) {
            $completed_courses = StudentCourse::where('student_id', $studentId)
                ->whereNotNull('course_id')
                ->where('completed', 1)
                ->count();

            $enrolled_courses = StudentCourse::where('student_id', $studentId)
                ->whereNotNull('course_id')
                ->count();

            $completed_programs = StudentCourse::where('student_id', $studentId)
                ->whereNull('course_id')
                ->whereNotNull('program_id')
                ->where('completed', 1)
                ->count();

            $enrolled_programs = StudentCourse::where('student_id', $studentId)
                ->whereNull('course_id')
                ->whereNotNull('program_id')
                ->count();

            return [
                'completed_courses' => $completed_courses,
                'enrolled_courses' => $enrolled_courses,
                'completed_programs' => $completed_programs,
                'enrolled_programs' => $enrolled_programs,
            ];
        });

        return response()->json($totals);
    }
}
