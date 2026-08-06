<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Exports\CourseQuizSampleExport;
use App\Http\Resources\LecturePageResource;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\CourseContentType;
use App\Models\CourseFinal;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswers;
use App\Models\Examination;
use App\Models\ExamSurvey;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Program;
use App\Models\ProgramContentType;
use App\Models\ProgramFinal;
use App\Models\Student;
use App\Models\StudentCourse;
use App\Models\StudentLectureProgress;
use App\Services\CourseProgressService;
use App\Services\ExamAttemptTimerService;
use App\Services\PaidAccessService;
use App\Services\Timeout\TimeoutCalculator;
use App\Services\Timeout\TimeoutCalculatorException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Throwable;

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

    public function quiz_sample($id, $type)
    {
        $fileName = 'quiz_sample_'.$id.'.xlsx';

        return Excel::download(new CourseQuizSampleExport($id, $type), $fileName);
    }

    public function quiz_upload(Request $request)
    {
        $file = $request->file('quiz_file');

        if (! $file) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $highestRow = (int) $sheet->getHighestRow();

        $quizzes = [];
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            if ($this->isQuizSpreadsheetRowEmpty($sheet, $rowIndex)) {
                continue;
            }

            $type = $this->readQuizSpreadsheetCellValue($sheet->getCell('B'.$rowIndex)) ?: 'single';
            $options = [];
            foreach (['F', 'G', 'H', 'I'] as $column) {
                $name = $this->readQuizOptionCellValue($sheet->getCell($column.$rowIndex));
                if ($name !== '') {
                    $options[] = ['name' => $name];
                }
            }

            $correctAnswerCell = $this->readQuizSpreadsheetCellValue($sheet->getCell('E'.$rowIndex));
            $quizzes[] = [
                'id' => $this->readQuizSpreadsheetCellValue($sheet->getCell('A'.$rowIndex)),
                'type' => $type,
                'question' => $this->readQuizSpreadsheetCellValue($sheet->getCell('C'.$rowIndex)),
                'answer' => $this->readQuizSpreadsheetCellValue($sheet->getCell('D'.$rowIndex)),
                'correct_answer' => $correctAnswerCell !== ''
                    ? ($type === 'single' ? (int) $correctAnswerCell : array_map('trim', explode(',', $correctAnswerCell)))
                    : ($type === 'single' ? null : []),
                'options' => $options,
            ];
        }

        return response()->json(['quizzes' => $quizzes]);
    }

    private function isQuizSpreadsheetRowEmpty($sheet, int $rowIndex): bool
    {
        for ($col = 1; $col <= 9; $col++) {
            $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$rowIndex);
            $value = in_array($col, [6, 7, 8, 9], true)
                ? $this->readQuizOptionCellValue($cell)
                : $this->readQuizSpreadsheetCellValue($cell);

            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    private function readQuizSpreadsheetCellValue(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    /**
     * Preserve Excel display text (e.g. "$15,000") instead of raw numeric 15000.
     */
    private function readQuizOptionCellValue(Cell $cell): string
    {
        $formatted = trim((string) ($cell->getFormattedValue() ?? ''));
        if ($formatted !== '') {
            return $formatted;
        }

        return $this->readQuizSpreadsheetCellValue($cell);
    }

    public function fetchcodes(Request $request)
    {

        $code = null;

        $code = fetchCodes($request->type);

        return response()->json(['code' => $code]);
    }

    public function fetchprice(Request $request)
    {
        $certificateType = self::normalizeCertificateTypeInput($request->input('certificate_type'));

        $discounted = 0.0;
        $original = 0.0;

        if ($request->type === 'course') {
            $data = Course::find($request->id);

            if (isset($data)) {
                $prices = $this->offerTitlePricesForCertificate($data, $certificateType);
                $discounted = $prices['discounted'];
                $original = $prices['original'];
            }
        } else {
            $data = Program::find($request->id);

            if (isset($data)) {
                $prices = $this->offerTitlePricesForCertificate($data, $certificateType);
                $discounted = $prices['discounted'];
                $original = $prices['original'];
            }
        }

        return response()->json([
            'discounted' => $discounted,
            'original' => $original,
        ]);
    }

    /**
     * List prices from course/program offer titles (`actual_price`, `discounted_price`), matching sort order used in legacy UI indexing.
     *
     * @param  Course|Program  $entity
     * @return array{discounted: float, original: float}
     */
    private function offerTitlePricesForCertificate($entity, ?string $certificateType): array
    {
        $certificateType ??= '';

        $index = match ($certificateType) {
            'pdf' => 2,
            'original' => 1,
            'pdf/original', 'pdf_original' => 0,
            default => null,
        };

        if ($index === null) {
            return ['discounted' => 0.0, 'original' => 0.0];
        }

        /*
         * Use list positions after sort_order, not Collection::get($idx) keyed lookup.
         * Also: many courses only enter discounted_price; treat that as list price when actual_price is empty.
         */
        $titles = $entity->offers()->orderBy('sort_order')->orderBy('id')->get()->values();
        $title = $titles->get($index);
        if ($title === null) {
            return ['discounted' => 0.0, 'original' => 0.0];
        }

        $actual = (float) ($title->actual_price ?? 0);
        $discounted = (float) ($title->discounted_price ?? 0);
        $listPrice = $actual > 0 ? $actual : $discounted;

        return [
            'discounted' => $discounted,
            'original' => $listPrice,
        ];
    }

    /**
     * Vueform may POST certificate_type as a plain string or as `{ "id": "pdf", ... }`.
     */
    private static function normalizeCertificateTypeInput(mixed $raw): string
    {
        if (\is_string($raw)) {
            return trim($raw);
        }
        if (\is_array($raw)) {
            foreach (['id', 'value'] as $k) {
                if (isset($raw[$k]) && $raw[$k] !== '' && $raw[$k] !== null) {
                    return trim((string) $raw[$k]);
                }
            }
        }
        if (\is_scalar($raw) && $raw !== '') {
            return trim((string) $raw);
        }

        return '';
    }

    public function check_smtp(Request $request)
    {
        $request->validate([
            'smtp_host' => 'required|string',
            'smtp_port' => 'required|numeric',
            'smtp_encryption' => 'nullable|string|in:ssl,tls,null',
            'smtp_username' => 'required|string',
            'smtp_password' => 'required|string',
        ]);

        try {
            // Handle null encryption (no ssl/tls)
            $encryption = $request->smtp_encryption === 'null' ? null : $request->smtp_encryption;

            // Set default SSL context options to disable certificate verification for TLS/SSL
            // This will be used for all SSL/TLS connections made during this request
            if ($encryption === 'tls' || $encryption === 'ssl') {
                stream_context_set_default([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ]);
            }

            // Build DSN (Data Source Name)
            $dsn = sprintf(
                'smtp://%s:%s@%s:%d',
                urlencode($request->smtp_username),
                urlencode($request->smtp_password),
                $request->smtp_host,
                $request->smtp_port
            );

            if ($encryption) {
                $dsn .= '?encryption='.$encryption;
            }

            // Create transport
            $transport = Transport::fromDsn($dsn);

            // Start connection test
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

    public function enrolled_course(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;
        $studentId = auth()->user()->student->id ?? null;

        $query = Course::with(['enrolled_programs', 'student_enrolled' => function ($q) use ($studentId) {
            $q->where('student_id', $studentId)
                ->with(['order.details']);
        }])
            ->whereHas('student_enrolled', function ($q) use ($studentId) {
                $q->where('student_id', $studentId);
            })
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['title', 'course_id', 'contact_hours', 'required_hours', 'pdf_course_price', 'pdf_course_discount_price', 'original_course_price', 'original_course_discount_price', 'passing_percentage', 'exam_duration', 'slug', 'sort_order'], 'like', "%{$search}%");
                });
            })
            ->orderBy($sort_by, $sort_type);

        // Resolve current page before pagination
        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $courses = $query->paginate($show_record);

        // If requested page is greater than last page → set to last page
        if ($cur_page > $courses->lastPage()) {
            Paginator::currentPageResolver(function () use ($courses) {
                return $courses->lastPage();
            });
            $courses = $query->paginate($show_record);
        }

        // Fallback: fetch certificate type directly from order_details for current student.
        $courseIds = collect($courses->items())->pluck('id')->all();
        $certificateTypeByCourse = [];
        if (! empty($courseIds)) {
            $certificateTypeByCourse = OrderDetail::query()
                ->select('order_details.course_id', 'order_details.certificate_type')
                ->join('orders', 'orders.id', '=', 'order_details.order_id')
                ->where('orders.customer_id', auth()->id())
                ->whereNull('orders.deleted_at')
                ->where('order_details.type', 'course')
                ->whereIn('order_details.course_id', $courseIds)
                ->orderByDesc('order_details.id')
                ->get()
                ->groupBy('course_id')
                ->map(fn ($rows) => $rows->first()->certificate_type)
                ->toArray();
        }

        $courses->getCollection()->transform(function ($course) use ($certificateTypeByCourse) {
            $course->purchased_certificate_type = $certificateTypeByCourse[$course->id] ?? null;

            return $course;
        });

        return response()->json(['data' => $courses]);
    }

    public function enrolled_program(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;
        $studentId = auth()->user()->student->id ?? null;

        $query = Program::with(['enrolled_courses' => function ($q) use ($studentId) {
            $q->where('student_id', $studentId);
        }, 'program_courses.course', 'offers.details', 'student_enrolled.order'])
            ->whereHas('student_enrolled', function ($q) use ($studentId) {
                $q->where('student_id', $studentId);
            })
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['title', 'program_id', 'contact_hours', 'required_hours', 'pdf_program_price', 'pdf_program_discount_price', 'original_program_price', 'original_program_discount_price', 'passing_percentage', 'exam_duration', 'slug', 'sort_order'], 'like', "%{$search}%");
                });
            })
            ->orderBy($sort_by, $sort_type);

        // Resolve current page before pagination
        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $programs = $query->paginate($show_record);

        // If requested page is greater than last page → set to last page
        if ($cur_page > $programs->lastPage()) {
            Paginator::currentPageResolver(function () use ($programs) {
                return $programs->lastPage();
            });
            $programs = $query->paginate($show_record);
        }

        // Fallback: fetch certificate type directly from order_details for current student.
        $programIds = collect($programs->items())->pluck('id')->all();
        $certificateTypeByProgram = [];
        if (! empty($programIds)) {
            $certificateTypeByProgram = OrderDetail::query()
                ->select('order_details.program_id', 'order_details.certificate_type')
                ->join('orders', 'orders.id', '=', 'order_details.order_id')
                ->where('orders.customer_id', auth()->id())
                ->whereNull('orders.deleted_at')
                ->where('order_details.type', 'program')
                ->whereIn('order_details.program_id', $programIds)
                ->orderByDesc('order_details.id')
                ->get()
                ->groupBy('program_id')
                ->map(fn ($rows) => $rows->first()->certificate_type)
                ->toArray();
        }

        $programs->getCollection()->transform(function ($program) use ($certificateTypeByProgram) {
            $program->purchased_certificate_type = $certificateTypeByProgram[$program->id] ?? null;

            return $program;
        });

        return response()->json(['data' => $programs]);
    }

    public function exam(string $type, string $slug)
    {

        if (! in_array($type, ['course', 'program'], true)) {
            abort(404);
        }

        $student = auth()->user()->student;
        if (! $student) {
            abort(403);
        }

        DB::beginTransaction();
        try {
            $student_course = StudentCourse::query()
                ->with(['course', 'program'])
                ->where('student_id', $student->id)
                ->when($type === 'course', fn ($q) => $q->whereHas('course', fn ($q) => $q->where('slug', $slug)))
                ->when($type === 'program', fn ($q) => $q->whereHas('program', fn ($q) => $q->where('slug', $slug)))
                ->first();

            if (! $student_course) {
                abort(404);
            }

            $paidAccess = app(PaidAccessService::class);
            if ($type === 'course' && $student_course->course) {
                $paidAccess->assertCanAccessCourse(
                    (int) $student->id,
                    $student_course->course,
                    $student_course->program_id ? (int) $student_course->program_id : null
                );
            } elseif ($type === 'program' && $student_course->program) {
                $paidAccess->assertCanAccessProgram((int) $student->id, $student_course->program);
            }

            if ($student_course->locked === 1) {
                return redirect()->route('dashboard')->with('error', 'Exam is locked. Please send a request to admin for approval to take the exam.');
            }

            $setting = getSetting()->exam_introduction;
            $certifyExam = getSetting()->certify_exam;
            $examRetake = ($student_course->course->retake_count) ?? ($student_course->program->retake_count) ?? 0;
            $examRetakeTime = ($student_course->course->retake_gap_minutes) ?? ($student_course->program->retake_gap_minutes) ?? 0;
            $passingPercentage = ($student_course->course->passing_percentage) ?? ($student_course->program->passing_percentage) ?? 0;
            $examDuration = ($student_course->course->exam_duration) ?? ($student_course->program->exam_duration) ?? 0;
            $setting = str_replace("['passing_percentage']", $this->formatIntroNumeric($passingPercentage), $setting);
            $setting = str_replace("['exam_duration']", $this->formatIntroNumeric($examDuration), $setting);
            $setting = str_replace("['exam_retake']", $this->formatIntroNumeric($examRetake), $setting);
            $setting = str_replace('[exam_retake]', $this->formatIntroNumeric($examRetake), $setting);
            $setting = str_replace("['exam_retake_time']", $this->formatIntroNumeric($examRetakeTime), $setting);
            $setting = str_replace('[exam_retake_time]', $this->formatIntroNumeric($examRetakeTime), $setting);
            $setting = str_replace("['course_link']", ($student_course->course->course_link) ?? ($student_course->program->program_link) ?? '', $setting);

            if (isset($student_course->course_id)) {
                $finals = CourseFinal::where('course_id', $student_course->course_id)->get();
                $type = 'course';
            } else {
                $finals = ProgramFinal::where('program_id', $student_course->program_id)->get();
                $type = 'program';
            }

            $ongoing_exam = Examination::with('exam_attempt_answers')
                ->where('student_course_id', $student_course->id)
                ->whereIn('exam_status', ['pending', 'ongoing'])
                ->first();

            $last_exam = Examination::with('exam_attempt_answers')
                ->where('student_course_id', $student_course->id)
                ->whereIn('exam_status', ['failed', 'passed'])
                ->latest('id')
                ->first();

            if ($last_exam) {

                // If already passed
                if ($last_exam->exam_status === 'passed') {
                    return redirect()
                        ->route('dashboard')
                        ->with('error', 'You have already passed the exam');
                }

                if ($last_exam->next_retake && Carbon::parse($last_exam->next_retake)->isFuture()) {

                    $formattedDate = Carbon::parse($last_exam->next_retake)
                        ->format('F j, Y, g:i A');

                    return redirect()
                        ->route('dashboard')
                        ->with('error', "You will be able to give exam again at {$formattedDate}");
                }
            }

            if (! isset($ongoing_exam)) {
                $data = [
                    'student_id' => auth()->user()->student->id,
                    'student_course_id' => $student_course->id,
                    'total_allocated_marks' => ($student_course->course->total_allocated_marks) ?? ($student_course->program->total_allocated_marks) ?? 0,
                    'passing_percentage' => ($student_course->course->passing_percentage) ?? ($student_course->program->passing_percentage) ?? 0,
                    'exam_duration' => ($student_course->course->exam_duration) ?? ($student_course->program->exam_duration) ?? 0,
                    // Store the numeric allowed retake value for this exam snapshot.
                    'retakes_allowed' => ($student_course->course->retakes_allowed) ?? ($student_course->program->retakes_allowed) ?? 0,
                    'retake_count' => ($student_course->course->retake_count) ?? ($student_course->program->retake_count) ?? 0,
                    'retake_gap_minutes' => ($student_course->course->retake_gap_minutes) ?? ($student_course->program->retake_gap_minutes) ?? 0,
                    'exam_start_datetime' => now(),
                    'exam_end_datetime' => now()->addMinutes((int) (($student_course->course->exam_duration) ?? ($student_course->program->exam_duration) ?? 0)),
                    'exam_status' => 'pending',
                ];

                $failed_exam = Examination::where('student_course_id', $student_course->id)
                    ->where('exam_status', 'failed')
                    ->first();

                if (isset($failed_exam)) {
                    $data['previous_exam_id'] = $failed_exam->id;
                }

                $ongoing_exam = Examination::CreateOrUpdateExamination($data);

                $student_course->update([
                    'exam_id' => $ongoing_exam->id,
                ]);
            }

            DB::commit();
        } catch (Throwable $th) {
            DB::rollBack();

            return response()->json(['error' => $th->getMessage()], 500);
        }

        $examAttemptIsStarted = ExamAttempt::query()
            ->where('user_id', auth()->id())
            ->where('examination_id', $ongoing_exam->id)
            ->where('is_started', true)
            ->exists();

        return Inertia::render('Exam', [
            'finals' => $finals,
            'examIntroduction' => $setting,
            'certifyExamTemplate' => $certifyExam,
            'student_course' => $student_course,
            'exam' => $ongoing_exam,
            'type' => $type,
            'exam_attempt_is_started' => $examAttemptIsStarted,
        ]);
    }

    public function question(Request $request)
    {
        $exam = Examination::find($request->examination_id);
        if (! $exam) {
            return response()->json(['error' => 'Examination not found'], 404);
        }

        $this->assertExamSessionStarted($exam);

        DB::beginTransaction();
        try {

            $exam->exam_status = 'ongoing';
            $exam->save();

            $data = [
                'examination_id' => $exam->id,
                'is_correct' => $request->is_correct,
                'time_spent_seconds' => $request->time_spent_seconds,
                'selected_option_id' => $request->selected_option_id,
                'user_answer_text' => $request->user_answer_text,
            ];

            if ($request->type === 'course') {
                $data['course_question_id'] = $request->question_id;
            } else {
                $data['program_question_id'] = $request->question_id;
            }

            $attempt_answer = ExamAttemptAnswers::create($data);

            ExamAttempt::query()
                ->where('user_id', auth()->id())
                ->where('examination_id', $exam->id)
                ->where('is_started', true)
                ->update([
                    'has_answered' => true,
                    'last_activity_at' => now(),
                ]);

            DB::commit();
        } catch (Throwable $th) {
            DB::rollBack();

            return response()->json(['error' => $th->getMessage()], 500);
        }

        return response()->json(['success' => 'Question answered successfully']);
    }

    public function finish_exam(Request $request, CourseProgressService $courseProgressService)
    {
        $exam = Examination::find($request->examination_id);
        if (! $exam) {
            return response()->json(['error' => 'Examination not found'], 404);
        }

        $this->assertExamSessionStarted($exam);

        $attempt = ExamAttempt::query()
            ->where('user_id', auth()->id())
            ->where('examination_id', $exam->id)
            ->first();

        if (! $attempt || ! $attempt->is_started) {
            return response()->json(['error' => 'Exam has not been started.'], 403);
        }

        // Sync authoritative remaining time; allow this request to complete the exam even when time is 0
        // (auto-submit on expiry). Mid-exam answers are blocked in ExamAttemptController::saveAnswer.
        app(ExamAttemptTimerService::class)->decayAndPersist($attempt, $exam);

        DB::beginTransaction();
        try {

            $data = [
                'examination_id' => $request->examination_id,
                'is_correct' => $request->is_correct,
                'time_spent_seconds' => $request->time_spent_seconds,
                'selected_option_id' => $request->selected_option_id,
                'user_answer_text' => $request->user_answer_text,
            ];

            if ($request->type === 'course') {
                $data['course_question_id'] = $request->question_id;
                $total_questions = CourseFinal::find($request->question_id);
                $total_questions = CourseFinal::where('course_id', $total_questions->course_id)->count();
            } else {
                $data['program_question_id'] = $request->question_id;
                $total_questions = ProgramFinal::find($request->question_id);
                $total_questions = ProgramFinal::where('program_id', $total_questions->program_id)->count();
            }

            $final_answer = ExamAttemptAnswers::create($data);

            $total_correct = ExamAttemptAnswers::where('examination_id', $request->examination_id)->where('is_correct', 1)->count();
            $total_incorrect = ExamAttemptAnswers::where('examination_id', $request->examination_id)->where('is_correct', 0)->count();
            $total_score = $total_questions > 0 ? round(($total_correct / $total_questions) * 100, 2) : 0;

            $total_retakes_taken = 0;

            if ($exam->previous_exam_id) {
                $total_retakes_taken1 = Examination::where('id', $exam->previous_exam_id)
                    ->where('student_course_id', $exam->student_course_id)
                    ->count();

                $total_retakes_taken2 = Examination::where('previous_exam_id', $exam->previous_exam_id)
                    ->where('student_course_id', $exam->student_course_id)
                    ->count();

                $total_retakes_taken = $total_retakes_taken1 + $total_retakes_taken2;
            }

            $student_course = StudentCourse::find($exam->student_course_id);

            $exam->taken_percentage = $total_score;

            if ($total_score >= $exam->passing_percentage) {
                $exam->exam_status = 'passed';
                $student_course->completed = 1;
                $student_course->completed_at = now();
                $student_course->save();

                if ($student_course->course_id) {
                    $courseProgressService->markExamPassed($student_course->fresh());
                }
            } else {
                $exam->exam_status = 'failed';
                $exam->taken_retake_count = $total_retakes_taken;

                if ($exam->retakes_allowed === 1) {
                    if ($exam->taken_retake_count >= $exam->retake_count) {
                        $student_course->locked = 1;
                        $student_course->unlocked_at = null;
                    } else {
                        $exam->next_retake = now()->addMinutes((float) ($exam->retake_gap_minutes ?? 0));
                    }
                } else {
                    $student_course->locked = 1;
                    $student_course->unlocked_at = null;
                }

                $student_course->save();
            }

            $exam->save();

            DB::commit();

            return response()->json(['message' => 'Exam finished successfully']);

        } catch (Throwable $th) {
            DB::rollBack();

            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function exam_complete($id)
    {

        $exam = Examination::with('exam_attempt_answers', 'student_course.course', 'student_course.program')
            ->find($id);

        $finals = [];
        if ($exam && $exam->student_course) {
            if (isset($exam->student_course->course_id)) {
                $finals = CourseFinal::where('course_id', $exam->student_course->course_id)->get();
            } elseif (isset($exam->student_course->program_id)) {
                $finals = ProgramFinal::where('program_id', $exam->student_course->program_id)->get();
            }
        }

        // Map exam_attempt_answers with their corresponding questions
        $attemptAnswers = $exam ? ($exam->exam_attempt_answers ?? []) : [];
        $questionsWithAnswers = [];

        foreach ($attemptAnswers as $attempt) {
            $questionId = $attempt->course_question_id ?? $attempt->program_question_id;
            if ($questionId) {
                $question = $finals->firstWhere('id', $questionId);
                if ($question) {
                    $questionsWithAnswers[] = [
                        'question' => $question,
                        'attempt' => $attempt,
                    ];
                }
            }
        }

        return Inertia::render('ExamComplete', [
            'exam' => $exam,
            'finals' => $finals,
            'questionsWithAnswers' => $questionsWithAnswers,
        ]);
    }

    public function exam_survey($id)
    {
        $exam = Examination::with('student_course.course', 'student_course.program')->find($id);

        return Inertia::render('ExamSurvey', [
            'exam' => $exam,
        ]);
    }

    public function submit_exam_survey(Request $request)
    {
        $validated = $request->validate([
            'examination_id' => ['required', 'exists:examinations,id'],
            'rating_1' => ['nullable', 'integer', 'min:1', 'max:3'],
            'rating_2' => ['nullable', 'integer', 'min:1', 'max:3'],
            'rating_3' => ['nullable', 'integer', 'min:1', 'max:3'],
            'rating_4' => ['nullable', 'integer', 'min:1', 'max:3'],
            'feedback_1' => ['nullable', 'string'],
            'feedback_2' => ['nullable', 'string'],
        ]);

        $exam = Examination::findOrFail($validated['examination_id']);
        $studentId = auth()->user()?->student?->id;

        if (! $studentId || (int) $exam->student_id !== (int) $studentId) {
            return response()->json(['message' => 'Unauthorized survey submission.'], 403);
        }

        ExamSurvey::updateOrCreate(
            ['examination_id' => $exam->id],
            [
                'student_id' => $studentId,
                'rating_1' => $validated['rating_1'] ?? null,
                'rating_2' => $validated['rating_2'] ?? null,
                'rating_3' => $validated['rating_3'] ?? null,
                'rating_4' => $validated['rating_4'] ?? null,
                'feedback_1' => $validated['feedback_1'] ?? null,
                'feedback_2' => $validated['feedback_2'] ?? null,
            ]
        );

        return response()->json(['message' => 'Survey submitted successfully.']);
    }

    public function course_lectures(string $type, string $slug)
    {
        if (! in_array($type, ['course', 'program'], true)) {
            abort(404);
        }

        $studentId = auth()->user()->student->id ?? null;

        if ($type === 'course') {
            $data = Course::with([
                'lectures.content_types.quizes', 'lectures.lecture_quizes',
                'offers.details', 'outcomes', 'course_fors',
            ])->where('slug', $slug)->first();
        } else {
            $data = Program::with([
                'lectures.content_types.quizes', 'lectures.lecture_quizes',
                'offers.details', 'outcomes', 'program_fors',
            ])->where('slug', $slug)->first();
        }

        if (! $data) {
            abort(404);
        }

        $entityId = $data->id;
        $scormModules = collect();

        if ($type === 'course') {
            $courseModules = DB::table('course_modules')
                ->where('course_id', $entityId)
                ->orderBy('order')
                ->get(['id', 'title', 'order', 'type']);

            if ($courseModules->isNotEmpty()) {
                $modulePackageIds = DB::table('scorm_packages')
                    ->whereIn('module_id', $courseModules->pluck('id')->all())
                    ->pluck('id', 'module_id');
                $packagePaths = DB::table('scorm_packages')
                    ->whereIn('id', $modulePackageIds->values()->all())
                    ->pluck('path', 'id');

                $firstScoByPackage = DB::table('scos')
                    ->whereIn('package_id', $modulePackageIds->values()->all())
                    ->orderByRaw('COALESCE(module_order, id) asc')
                    ->get(['id', 'package_id', 'launch_url'])
                    ->groupBy('package_id')
                    ->map(fn ($rows) => $rows->first());

                $scormModules = $courseModules
                    ->map(function ($module) use ($modulePackageIds, $firstScoByPackage, $packagePaths) {
                        $packageId = (int) ($modulePackageIds[$module->id] ?? 0);
                        $firstSco = $packageId > 0 ? $firstScoByPackage->get($packageId) : null;
                        $path = trim((string) ($packagePaths[$packageId] ?? ''), '/');
                        $rawLaunch = trim((string) ($firstSco->launch_url ?? ''));
                        $file = ltrim($rawLaunch, '/');
                        $launchUrl = '';

                        if ($rawLaunch !== '') {
                            if (str_starts_with($rawLaunch, 'http://') || str_starts_with($rawLaunch, 'https://') || str_starts_with($rawLaunch, '/storage/')) {
                                $launchUrl = $rawLaunch;
                            } elseif ($path !== '') {
                                $launchUrl = '/storage/'.$path.'/'.$file;
                            } else {
                                // Fallback for legacy rows where package path is unavailable.
                                // Most launch files are under storage/public.
                                $launchUrl = '/storage/'.$file;
                            }
                        }

                        return [
                            'module_id' => (int) $module->id,
                            'sco_id' => isset($firstSco->id) ? (int) $firstSco->id : null,
                            'package_id' => $packageId > 0 ? $packageId : null,
                            'title' => (string) ($module->title ?? 'SCORM Module'),
                            'order' => (int) ($module->order ?? 0),
                            'launch_url' => $launchUrl,
                            'scorm_url' => $launchUrl,
                        ];
                    })
                    ->filter(fn ($module) => ! empty($module['launch_url']))
                    ->values();
            }

            if ($scormModules->isEmpty()) {
                $scormModules = DB::table('scos as s')
                    ->join('course_scorm as cs', 'cs.scorm_package_id', '=', 's.package_id')
                    ->join('scorm_packages as sp', 'sp.id', '=', 's.package_id')
                    ->where('cs.course_id', $entityId)
                    ->orderByRaw('COALESCE(s.module_order, s.id) asc')
                    ->get(['s.id', 's.package_id', 's.title', 's.launch_url', 'sp.path as package_path', DB::raw('COALESCE(s.module_order, s.id) as `order`')])
                    ->map(function ($row) {
                        $path = trim((string) ($row->package_path ?? ''), '/');
                        $rawLaunch = trim((string) ($row->launch_url ?? ''));
                        $file = ltrim($rawLaunch, '/');
                        $launchUrl = '';

                        if ($rawLaunch !== '') {
                            if (str_starts_with($rawLaunch, 'http://') || str_starts_with($rawLaunch, 'https://') || str_starts_with($rawLaunch, '/storage/')) {
                                $launchUrl = $rawLaunch;
                            } elseif ($path !== '') {
                                $launchUrl = '/storage/'.$path.'/'.$file;
                            } else {
                                // Fallback for legacy rows where package path is unavailable.
                                $launchUrl = '/storage/'.$file;
                            }
                        }

                        return [
                            'module_id' => null,
                            'sco_id' => (int) $row->id,
                            'package_id' => isset($row->package_id) ? (int) $row->package_id : null,
                            'title' => (string) ($row->title ?? 'SCORM Module'),
                            'order' => (int) ($row->order ?? 0),
                            'launch_url' => $launchUrl,
                            'scorm_url' => $launchUrl,
                        ];
                    })
                    ->filter(fn ($module) => ! empty($module['launch_url']))
                    ->values();
            }
        }

        // Get lecture progress for the student
        $totalLectures = $data->lectures->count();
        $completedLectureIds = [];
        $completedSections = [];
        $progressStats = ['completed' => 0, 'total' => $totalLectures, 'percentage' => 0];
        $completedScormScoIds = [];
        $introductionCompleted = false;

        $isEnrolled = false;
        $hasContentAccess = false;
        $requiresPaidAccess = false;
        $accessDeniedMessage = PaidAccessService::ACCESS_DENIED_MESSAGE;
        $paidAccess = app(PaidAccessService::class);

        if ($studentId) {
            $completedLectureIds = StudentLectureProgress::getCompletedLectureIds($studentId, $type, $entityId);
            $progressStats = StudentLectureProgress::getProgressStats($studentId, $type, $entityId, $totalLectures);
            $completedSections = StudentLectureProgress::getBatchCompletedSectionsMap($studentId, $type, $entityId);
            $introductionCompleted = StudentLectureProgress::isIntroductionComplete($studentId, $type, $entityId);

            if ($type === 'course') {
                $access = $paidAccess->resolveCourseAccess($studentId, $data);
                $requiresPaidAccess = $access['requires_paid_access'];
                $hasContentAccess = $access['has_access'];
                $accessDeniedMessage = $access['message'];
                $isEnrolled = $paidAccess->isEnrolled($studentId, $data->id, null);
            } else {
                $access = $paidAccess->resolveProgramAccess($studentId, $data);
                $requiresPaidAccess = $access['requires_paid_access'];
                $hasContentAccess = $access['has_access'];
                $accessDeniedMessage = $access['message'];
                $isEnrolled = StudentCourse::where('student_id', $studentId)
                    ->where('program_id', $entityId)
                    ->whereNull('deleted_at')
                    ->exists();
            }
        }

        if ($studentId && $scormModules->isNotEmpty()) {
            $scoIds = collect($scormModules)
                ->pluck('sco_id')
                ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($scoIds->isNotEmpty()) {
                $completedScormScoIds = DB::table('scorm_attempts')
                    ->where('user_id', auth()->id())
                    ->whereIn('sco_id', $scoIds->all())
                    ->whereIn('status', ['completed', 'passed'])
                    ->pluck('sco_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        return Inertia::render('CourseLectures', [
            'data' => (new LecturePageResource($data))->resolve(),
            'type' => $type,
            'isEnrolled' => $isEnrolled,
            'hasContentAccess' => $hasContentAccess,
            'requiresPaidAccess' => $requiresPaidAccess,
            'accessDeniedMessage' => $accessDeniedMessage,
            'completedLectureIds' => $completedLectureIds,
            'completedSections' => $completedSections,
            'progressStats' => $progressStats,
            'scormModules' => $scormModules,
            'completedScormScoIds' => $completedScormScoIds,
            'introductionCompleted' => $introductionCompleted,
        ]);
    }

    /**
     * Lazy-load a single lecture section's heavy content (text, media paths, quiz data).
     * Used by the lecture player to reduce initial Inertia payload and cache on demand.
     */
    public function lectureSectionContent(Request $request)
    {
        $request->validate([
            'type' => 'required|in:course,program',
            'lecture_id' => 'required|integer',
            'section_id' => 'required|integer',
            'course_id' => 'nullable|integer',
            'program_id' => 'nullable|integer',
        ]);

        $studentId = auth()->user()->student->id ?? null;
        if (! $studentId) {
            return response()->json(['error' => 'Student not found'], 404);
        }

        $paidAccess = app(PaidAccessService::class);

        if ($request->type === 'course') {
            $course = Course::find($request->course_id);
            if (! $course) {
                return response()->json(['error' => 'Course not found'], 404);
            }
            $paidAccess->assertCanAccessCourse($studentId, $course, null);

            $section = CourseContentType::query()
                ->where('id', $request->section_id)
                ->where('course_content_id', $request->lecture_id)
                ->with('quizes')
                ->first();
        } else {
            $program = Program::find($request->program_id);
            if (! $program) {
                return response()->json(['error' => 'Program not found'], 404);
            }
            $paidAccess->assertCanAccessProgram($studentId, $program);

            $section = ProgramContentType::query()
                ->where('id', $request->section_id)
                ->where('program_content_id', $request->lecture_id)
                ->with('quizes')
                ->first();
        }

        if (! $section) {
            return response()->json(['error' => 'Section not found'], 404);
        }

        return response()->json([
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'type' => $section->type,
                'sort_order' => $section->sort_order,
                'text' => $section->text,
                'video_url' => $section->video_url,
                'video_file' => $section->video_file,
                'pdf' => $section->pdf,
                'quizes' => $section->relationLoaded('quizes') ? $section->quizes : [],
                'quizzes' => $section->relationLoaded('quizes') ? $section->quizes : [],
            ],
        ]);
    }

    /**
     * Mark a lecture as completed
     */
    public function markLectureComplete(Request $request, CourseProgressService $courseProgressService)
    {
        $request->validate([
            'type' => 'required|in:course,program',
            'lecture_id' => 'required|integer',
            'course_id' => 'nullable|integer',
            'program_id' => 'nullable|integer',
            'section_id' => 'nullable|integer', // Optional section_id for multi-section lectures
        ]);

        $studentId = auth()->user()->student->id ?? null;

        if (! $studentId) {
            return response()->json(['error' => 'Student not found'], 404);
        }

        $paidAccess = app(PaidAccessService::class);
        if ($request->type === 'course' && $request->course_id) {
            $course = Course::find($request->course_id);
            if ($course) {
                $paidAccess->assertCanAccessCourse($studentId, $course, $request->program_id ? (int) $request->program_id : null);
            }
        } elseif ($request->type === 'program' && $request->program_id) {
            $program = Program::find($request->program_id);
            if ($program) {
                $paidAccess->assertCanAccessProgram($studentId, $program);
            }
        }

        $parentId = $request->type === 'course' ? (int) $request->course_id : (int) $request->program_id;

        if ((int) $request->lecture_id === StudentLectureProgress::INTRODUCTION_LECTURE_ID) {
            $progress = StudentLectureProgress::markIntroductionComplete(
                $studentId,
                $request->type,
                $request->course_id,
                $request->program_id
            );

            if ($request->type === 'course') {
                $totalLectures = Course::find($parentId)?->lectures()->count() ?? 0;
            } else {
                $totalLectures = Program::find($parentId)?->lectures()->count() ?? 0;
            }

            $progressStats = StudentLectureProgress::getProgressStats($studentId, $request->type, $parentId, $totalLectures);

            return response()->json([
                'message' => 'Introduction marked as completed',
                'progress' => $progress,
                'progressStats' => $progressStats,
                'introductionCompleted' => true,
            ]);
        }

        // If section_id is provided, save section progress
        if ($request->has('section_id') && $request->section_id) {
            $progress = StudentLectureProgress::markSectionComplete(
                $studentId,
                $request->type,
                $request->lecture_id,
                $request->section_id,
                $request->course_id,
                $request->program_id
            );
        } else {
            // No section_id - mark entire lecture as complete (backward compatibility)
            $progress = StudentLectureProgress::markComplete(
                $studentId,
                $request->type,
                $request->lecture_id,
                $request->course_id,
                $request->program_id
            );
        }

        // Get updated stats
        $parentId = $request->type === 'course' ? $request->course_id : $request->program_id;

        if ($request->type === 'course') {
            $totalLectures = Course::find($parentId)?->lectures()->count() ?? 0;
        } else {
            $totalLectures = Program::find($parentId)?->lectures()->count() ?? 0;
        }

        $progressStats = StudentLectureProgress::getProgressStats($studentId, $request->type, $parentId, $totalLectures);

        if ($request->type === 'course' && $request->course_id) {
            $courseProgressService->updateProgress($studentId, (int) $request->course_id);
            $progressStats = StudentLectureProgress::getProgressStats($studentId, 'course', (int) $request->course_id, $totalLectures);
        }

        // Check if all lectures are completed, and if so, mark the program shell rows (course sync handled above)
        $allLecturesCompleted = $progressStats['completed'] >= $totalLectures && $totalLectures > 0;
        if ($allLecturesCompleted && $request->type === 'program') {
            StudentCourse::where('student_id', $studentId)
                ->where('program_id', $parentId)
                ->update(['lecture_completed' => true, 'lecture_completed_at' => now()]);
        }

        return response()->json([
            'message' => $request->has('section_id') && $request->section_id ? 'Section marked as completed' : 'Lecture marked as completed',
            'progress' => $progress,
            'progressStats' => $progressStats,
            'allLecturesCompleted' => $allLecturesCompleted,
        ]);
    }

    /**
     * Mark a lecture as incomplete
     */
    public function markLectureIncomplete(Request $request, CourseProgressService $courseProgressService)
    {
        $request->validate([
            'type' => 'required|in:course,program',
            'lecture_id' => 'required|integer',
            'course_id' => 'nullable|integer',
            'program_id' => 'nullable|integer',
        ]);

        $studentId = auth()->user()->student->id ?? null;

        if (! $studentId) {
            return response()->json(['error' => 'Student not found'], 404);
        }

        $paidAccess = app(PaidAccessService::class);
        if ($request->type === 'course' && $request->course_id) {
            $course = Course::find($request->course_id);
            if ($course) {
                $paidAccess->assertCanAccessCourse($studentId, $course, $request->program_id ? (int) $request->program_id : null);
            }
        } elseif ($request->type === 'program' && $request->program_id) {
            $program = Program::find($request->program_id);
            if ($program) {
                $paidAccess->assertCanAccessProgram($studentId, $program);
            }
        }

        StudentLectureProgress::markIncomplete($studentId, $request->type, $request->lecture_id);

        // Get updated stats
        $parentId = $request->type === 'course' ? $request->course_id : $request->program_id;

        if ($request->type === 'course') {
            $totalLectures = Course::find($parentId)?->lectures()->count() ?? 0;
        } else {
            $totalLectures = Program::find($parentId)?->lectures()->count() ?? 0;
        }

        $progressStats = StudentLectureProgress::getProgressStats($studentId, $request->type, $parentId, $totalLectures);

        if ($request->type === 'course' && $request->course_id) {
            $courseProgressService->updateProgress($studentId, (int) $request->course_id);
            $progressStats = StudentLectureProgress::getProgressStats($studentId, 'course', (int) $request->course_id, $totalLectures);
        }

        return response()->json([
            'message' => 'Lecture marked as incomplete',
            'progressStats' => $progressStats,
        ]);
    }

    /**
     * Generate a PDF access token for the current session and specific PDF path
     */
    public function generatePdfToken(Request $request)
    {
        if (! auth()->check()) {
            abort(403, 'Unauthorized access');
        }

        $request->validate([
            'path' => 'required|string',
        ]);

        $pdfPath = $request->get('path');
        $userId = auth()->id();
        $userIp = $request->ip();
        $userAgent = $request->userAgent();

        // Generate a token specific to this PDF path, user, IP, and session
        $tokenData = [
            'path' => $pdfPath,
            'user_id' => $userId,
            'ip' => $userIp,
            'user_agent' => hash('sha256', $userAgent ?: ''),
            'timestamp' => now()->timestamp,
            'session_id' => session()->getId(),
        ];

        $payload = json_encode($tokenData, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return response()->json(['message' => 'Invalid PDF path.'], 400);
        }

        $appKey = (string) config('app.key');
        if ($appKey === '') {
            \Log::error('APP_KEY is empty; cannot issue PDF token.');

            return response()->json(['message' => 'Server configuration error.'], 500);
        }

        // Create a signed token that includes all this data
        $token = hash_hmac('sha256', $payload, $appKey.session()->getId());

        // Store token data in session (keyed by token hash for easy lookup)
        $tokens = session('pdf_tokens', []);
        $tokens[$token] = $tokenData;
        session(['pdf_tokens' => $tokens]);

        return response()->json(['token' => $token]);
    }

    /**
     * Serve protected PDF files with download prevention
     */
    public function serveProtectedPdf(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
            'token' => 'required|string',
        ]);

        // Ensure user is authenticated
        if (! auth()->check()) {
            abort(403, 'Unauthorized access');
        }

        $providedToken = $request->get('token');
        $requestedPath = $request->get('path');
        $tokens = session('pdf_tokens', []);

        // Verify token exists and is valid (stored by token hash as key)
        if (! isset($tokens[$providedToken])) {
            abort(403, 'Invalid PDF access token. Please access PDFs through the course lectures page.');
        }

        $tokenData = $tokens[$providedToken];

        $payload = json_encode($tokenData, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            abort(403, 'Invalid token data.');
        }
        $appKey = (string) config('app.key');
        $expectedToken = hash_hmac('sha256', $payload, $appKey.session()->getId());
        if (! hash_equals($expectedToken, $providedToken)) {
            abort(403, 'Token verification failed. Please access PDFs through the course lectures page.');
        }

        $userId = auth()->id();
        $userIp = $request->ip();
        $userAgent = hash('sha256', $request->userAgent() ?: '');

        // Verify token is for the correct PDF path
        if ($tokenData['path'] !== $requestedPath) {
            abort(403, 'Token does not match requested PDF. Please access PDFs through the course lectures page.');
        }

        // Verify token belongs to current user
        if ($tokenData['user_id'] != $userId) {
            abort(403, 'Token does not belong to current user. Please access PDFs through the course lectures page.');
        }

        // Verify session matches
        if ($tokenData['session_id'] !== session()->getId()) {
            abort(403, 'Invalid session. Please access PDFs through the course lectures page.');
        }

        // Token expires after 60 seconds (short window, but allows for PDF.js loading time)
        if ((now()->timestamp - $tokenData['timestamp']) > 60) {
            // Remove expired token
            unset($tokens[$providedToken]);
            session(['pdf_tokens' => $tokens]);
            abort(403, 'PDF access token has expired. Please refresh the page.');
        }

        // Verify IP address matches (prevent token sharing)
        // Note: IP can change with mobile networks/proxies, so we log but don't block
        if ($tokenData['ip'] !== $userIp) {
            \Log::warning('PDF access token used from different IP', [
                'user_id' => $userId,
                'expected_ip' => $tokenData['ip'],
                'actual_ip' => $userIp,
                'path' => $requestedPath,
            ]);
            // Don't block - IP can change legitimately with mobile networks
            // The other validations (session, user, path, expiration) are sufficient
        }

        // Check if this is a direct browser navigation (not from PDF.js)
        // Note: PDF.js fetch requests may not always have referer, so we're more lenient here
        // The token validation above is the primary security measure
        $referer = $request->header('Referer');
        $appUrl = config('app.url');
        $isDirectAccess = ! $referer || strpos($referer, $appUrl) === false;

        // Only block if it's clearly a direct document navigation (not a fetch request)
        $secFetchDest = $request->header('Sec-Fetch-Dest');
        if ($isDirectAccess && $secFetchDest === 'document') {
            // Log and block direct browser navigation
            \Log::warning('Direct PDF access blocked', [
                'user_id' => $userId,
                'path' => $requestedPath,
                'ip' => $userIp,
            ]);
            abort(403, 'Direct PDF access is not allowed. Please access PDFs through the course lectures page.');
        }

        // Note: We don't remove token after use because PDF.js may need to fetch multiple chunks
        // The expiration time (60 seconds) is sufficient security
        // Token is already validated for path, user, session, and expiration

        $pdfPath = $request->get('path');

        // Remove leading slash if present
        $pdfPath = ltrim($pdfPath, '/');

        // Security: Ensure path is within storage directory and is a PDF
        if (! str_ends_with(strtolower($pdfPath), '.pdf')) {
            abort(400, 'Invalid file type');
        }

        // Prevent path traversal attacks
        if (strpos($pdfPath, '..') !== false || strpos($pdfPath, '/') === 0) {
            abort(400, 'Invalid file path');
        }

        // Check if file exists in public storage
        $fullPath = storage_path('app/public/'.$pdfPath);

        if (! file_exists($fullPath)) {
            // Try direct path if storage link exists (public/storage symlink)
            $publicPath = public_path('storage/'.$pdfPath);
            if (file_exists($publicPath)) {
                $fullPath = $publicPath;
            } else {
                // Fallback: try .pdf.pdf (common when original had .pdf extension)
                $pdfPathAlt = preg_replace('/\.pdf$/i', '.pdf.pdf', $pdfPath);
                $fullPathAlt = storage_path('app/public/'.$pdfPathAlt);
                if (file_exists($fullPathAlt)) {
                    $fullPath = $fullPathAlt;
                } else {
                    $publicPathAlt = public_path('storage/'.$pdfPathAlt);
                    if (file_exists($publicPathAlt)) {
                        $fullPath = $publicPathAlt;
                    } else {
                        abort(404, 'PDF file not found');
                    }
                }
            }
        }

        // Get file content
        $fileContent = file_get_contents($fullPath);
        $fileName = basename($pdfPath);

        // Return PDF with headers that prevent download and force inline display
        // Note: These headers help but cannot fully prevent determined users
        return Response::make($fileContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="protected.pdf"', // Use generic name
            'Content-Length' => strlen($fileContent),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Download-Options' => 'noopen',
            'Content-Security-Policy' => "frame-ancestors 'self'; default-src 'self'; script-src 'none';",
            'Accept-Ranges' => 'none',
            // Additional security headers
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ]);
    }

    /**
     * Generate a video access token for the current session and specific video path
     */
    public function generateVideoToken(Request $request)
    {
        if (! auth()->check()) {
            abort(403, 'Unauthorized access');
        }

        $request->validate([
            'path' => 'required|string',
        ]);

        $videoPath = $request->get('path');
        $userId = auth()->id();
        $userIp = $request->ip();
        $userAgent = $request->userAgent();

        // Generate a token specific to this video path, user, IP, and session
        $tokenData = [
            'path' => $videoPath,
            'user_id' => $userId,
            'ip' => $userIp,
            'user_agent' => hash('sha256', $userAgent ?: ''),
            'timestamp' => now()->timestamp,
            'session_id' => session()->getId(),
        ];

        // Create a signed token that includes all this data
        $token = hash_hmac('sha256', json_encode($tokenData), config('app.key').session()->getId());

        // Store token data in session (keyed by token hash for easy lookup)
        $tokens = session('video_tokens', []);
        $tokens[$token] = $tokenData;
        session(['video_tokens' => $tokens]);

        return response()->json(['token' => $token]);
    }

    /**
     * Serve protected video files with download prevention
     */
    public function serveProtectedVideo(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
            'token' => 'required|string',
        ]);

        // Ensure user is authenticated
        if (! auth()->check()) {
            abort(403, 'Unauthorized access');
        }

        $providedToken = $request->get('token');
        $requestedPath = $request->get('path');
        $tokens = session('video_tokens', []);

        // Verify token exists and is valid (stored by token hash as key)
        if (! isset($tokens[$providedToken])) {
            abort(403, 'Invalid video access token. Please access videos through the course lectures page.');
        }

        $tokenData = $tokens[$providedToken];

        // Verify token by recomputing (security check)
        $expectedToken = hash_hmac('sha256', json_encode($tokenData), config('app.key').session()->getId());
        if (! hash_equals($expectedToken, $providedToken)) {
            abort(403, 'Token verification failed. Please access videos through the course lectures page.');
        }

        // Verify token is for the correct video path
        if ($tokenData['path'] !== $requestedPath) {
            abort(403, 'Token does not match requested video. Please access videos through the course lectures page.');
        }

        // Verify token belongs to current user
        if ($tokenData['user_id'] != auth()->id()) {
            abort(403, 'Token does not belong to current user. Please access videos through the course lectures page.');
        }

        // Verify session matches
        if ($tokenData['session_id'] !== session()->getId()) {
            abort(403, 'Invalid session. Please access videos through the course lectures page.');
        }

        // Token expires after 60 seconds
        if ((now()->timestamp - $tokenData['timestamp']) > 60) {
            unset($tokens[$providedToken]);
            session(['video_tokens' => $tokens]);
            abort(403, 'Video access token has expired. Please refresh the page.');
        }

        $videoPath = $request->get('path');
        $videoPath = ltrim($videoPath, '/');

        // Security: Ensure path is a video file
        $allowedExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'wmv', 'flv', 'mkv'];
        $extension = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
        if (! in_array($extension, $allowedExtensions)) {
            abort(400, 'Invalid file type');
        }

        // Prevent path traversal attacks
        if (strpos($videoPath, '..') !== false || strpos($videoPath, '/') === 0) {
            abort(400, 'Invalid file path');
        }

        // Check if file exists in public storage
        $fullPath = storage_path('app/public/'.$videoPath);

        if (! file_exists($fullPath)) {
            $publicPath = public_path('storage/'.$videoPath);
            if (file_exists($publicPath)) {
                $fullPath = $publicPath;
            } else {
                abort(404, 'Video file not found');
            }
        }

        // Get file content
        $fileContent = file_get_contents($fullPath);
        $fileName = basename($videoPath);

        // Determine content type based on extension
        $contentTypes = [
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'mkv' => 'video/x-matroska',
        ];
        $contentType = $contentTypes[$extension] ?? 'video/mp4';

        // Check if this is a range request (for video streaming)
        $range = $request->header('Range');
        $fileSize = filesize($fullPath);

        if ($range) {
            // Handle range requests for video streaming
            preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
            $start = intval($matches[1]);
            $end = ! empty($matches[2]) ? intval($matches[2]) : $fileSize - 1;
            $length = $end - $start + 1;

            // Read partial file content
            $handle = fopen($fullPath, 'rb');
            fseek($handle, $start);
            $content = fread($handle, $length);
            fclose($handle);

            return Response::make($content, 206, [
                'Content-Type' => $contentType,
                'Content-Length' => $length,
                'Content-Range' => "bytes $start-$end/$fileSize",
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, no-cache, no-store, must-revalidate, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'X-Download-Options' => 'noopen',
            ]);
        }

        // Return full video with headers that prevent download
        return Response::make($fileContent, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="protected.'.$extension.'"',
            'Content-Length' => strlen($fileContent),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Download-Options' => 'noopen',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'Accept-Ranges' => 'bytes', // Allow range requests for video streaming
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ]);
    }

    protected function assertExamSessionStarted(Examination $exam): void
    {
        $started = ExamAttempt::query()
            ->where('user_id', auth()->id())
            ->where('examination_id', $exam->id)
            ->where('is_started', true)
            ->exists();

        if (! $started) {
            abort(403, 'Exam has not been started.');
        }
    }

    /**
     * Human-friendly numbers for exam intro HTML (e.g. 60.00 → "60", 75.5 → "75.5").
     */
    private function formatIntroNumeric(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $n = (float) $value;
        if (! is_finite($n)) {
            return '0';
        }

        if (abs($n - round($n)) < 1e-9) {
            return (string) (int) round($n);
        }

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
