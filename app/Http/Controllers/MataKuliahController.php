<?php

namespace App\Http\Controllers;

use App\Models\MataKuliah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class MataKuliahController extends Controller
{
    /**
     * Menampilkan daftar mata kuliah.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = MataKuliah::with('dosen')
            ->withCount([
                'approvedStudents',
                'materials',
                'assignments',
            ]);

        if ($user->role === 'dosen') {
            $query->where('dosen_id', $user->id);
        }

        if ($request->filled('kode_mk')) {
            $query->where(
                'kode_mk',
                'like',
                '%' . $request->kode_mk . '%'
            );
        }

        if ($request->filled('nama_mk')) {
            $query->where(
                'nama_mk',
                'like',
                '%' . $request->nama_mk . '%'
            );
        }

        if ($request->filled('semester')) {
            $query->where('semester', $request->semester);
        }

        $courses = $query
            ->latest()
            ->paginate(9)
            ->withQueryString();

        return view('courses.index', compact('courses'));
    }

    /**
     * Form tambah mata kuliah.
     */
    public function create()
    {
        abort_unless(Auth::user()->role === 'dosen', 403);

        return view('courses.create');
    }

    /**
     * Menyimpan mata kuliah baru.
     */
    public function store(Request $request)
    {
        abort_unless(Auth::user()->role === 'dosen', 403);

        $validated = $request->validate([
            'kode_mk' => [
                'required',
                'string',
                'max:20',
                'unique:courses,kode_mk',
            ],
            'nama_mk' => [
                'required',
                'string',
                'max:255',
            ],
            'deskripsi' => [
                'nullable',
                'string',
            ],
            'sks' => [
                'required',
                'integer',
                'min:1',
                'max:6',
            ],
            'semester' => [
                'required',
                'string',
                'max:50',
            ],
        ]);

        $validated['dosen_id'] = Auth::id();

        // Sinkronisasi dengan kolom lama database SQLite.
        $validated['kode_mata_kuliah'] = $validated['kode_mk'];
        $validated['nama'] = $validated['nama_mk'];

        $course = MataKuliah::create($validated);

        return redirect()
            ->route('courses.show', $course->id)
            ->with('success', 'Mata kuliah berhasil ditambahkan.');
    }

    /**
     * Menampilkan detail mata kuliah.
     */
    public function show(MataKuliah $course)
    {
        $user = Auth::user();

        if ($user->role === 'dosen') {
            abort_unless(
                (int) $course->dosen_id === (int) $user->id,
                403
            );

            $course->load([
                'dosen',
                'materials',
                'assignments.submissions',
                'approvedStudents',
                'pendingStudents',
            ]);

            return view('courses.show', compact('course'));
        }

        $enrollment = $course
            ->students()
            ->where('users.id', $user->id)
            ->first();

        $enrollmentStatus = $enrollment?->pivot?->status;
        $isApproved = $enrollmentStatus === 'approved';

        $course->load('dosen');

        if ($isApproved) {
            $course->load([
                'materials',
                'assignments',
            ]);

            // Mahasiswa hanya dapat melihat pengumpulan miliknya.
            $course->assignments->each(
                function ($assignment) use ($user) {
                    $assignment->load([
                        'submissions' => function ($query) use ($user) {
                            $query->where('user_id', $user->id);
                        },
                    ]);
                }
            );
        }

        return view('courses.show', [
            'course' => $course,
            'enrollmentStatus' => $enrollmentStatus,
            'isApproved' => $isApproved,
        ]);
    }

    /**
     * Form edit mata kuliah.
     */
    public function edit(MataKuliah $course)
    {
        $this->authorizeCourseLecturer($course);

        return view('courses.edit', compact('course'));
    }

    /**
     * Memperbarui mata kuliah.
     */
    public function update(Request $request, MataKuliah $course)
    {
        $this->authorizeCourseLecturer($course);

        $validated = $request->validate([
            'kode_mk' => [
                'required',
                'string',
                'max:20',
                Rule::unique('courses', 'kode_mk')
                    ->ignore($course->id),
            ],
            'nama_mk' => [
                'required',
                'string',
                'max:255',
            ],
            'deskripsi' => [
                'nullable',
                'string',
            ],
            'sks' => [
                'required',
                'integer',
                'min:1',
                'max:6',
            ],
            'semester' => [
                'required',
                'string',
                'max:50',
            ],
        ]);

        // Sinkronisasi nilai untuk kolom lama.
        $validated['kode_mata_kuliah'] = $validated['kode_mk'];
        $validated['nama'] = $validated['nama_mk'];

        $course->update($validated);

        return redirect()
            ->route('courses.show', $course->id)
            ->with('success', 'Mata kuliah berhasil diperbarui.');
    }

    /**
     * Menghapus mata kuliah.
     */
    public function destroy(MataKuliah $course)
    {
        $this->authorizeCourseLecturer($course);

        $nama = $course->nama_mk;
        $kode = $course->kode_mk;

        $course->delete();

        return redirect()
            ->route('courses.index')
            ->with(
                'success',
                "Mata kuliah {$nama} ({$kode}) berhasil dihapus."
            );
    }

    /**
     * Mahasiswa mengajukan pendaftaran.
     */
    public function enrollRequest(MataKuliah $course)
    {
        abort_unless(Auth::user()->role === 'mahasiswa', 403);

        $user = Auth::user();

        $existing = $course
            ->students()
            ->where('users.id', $user->id)
            ->first();

        if ($existing) {
            $status = $existing->pivot->status;

            if ($status === 'approved') {
                return back()->with(
                    'info',
                    'Anda sudah terdaftar pada mata kuliah ini.'
                );
            }

            if ($status === 'pending') {
                return back()->with(
                    'info',
                    'Pengajuan Anda masih menunggu persetujuan dosen.'
                );
            }

            if ($status === 'rejected') {
                $course->students()->updateExistingPivot($user->id, [
                    'status' => 'pending',
                ]);

                return back()->with(
                    'success',
                    'Pengajuan pendaftaran berhasil dikirim ulang.'
                );
            }
        }

        $course->students()->attach($user->id, [
            'status' => 'pending',
        ]);

        return back()->with(
            'success',
            'Pengajuan pendaftaran berhasil dikirim.'
        );
    }

    /**
     * Dosen menyetujui pendaftaran mahasiswa.
     */
    public function approveStudent(MataKuliah $course, $studentId)
    {
        $this->authorizeCourseLecturer($course);

        $student = $course
            ->pendingStudents()
            ->where('users.id', $studentId)
            ->first();

        if (!$student) {
            return back()->with(
                'error',
                'Pengajuan mahasiswa tidak ditemukan atau sudah diproses.'
            );
        }

        $course->students()->updateExistingPivot($studentId, [
            'status' => 'approved',
        ]);

        return back()->with(
            'success',
            'Mahasiswa berhasil disetujui.'
        );
    }

    /**
     * Dosen menolak pendaftaran mahasiswa.
     */
    public function rejectStudent(MataKuliah $course, $studentId)
    {
        $this->authorizeCourseLecturer($course);

        $student = $course
            ->pendingStudents()
            ->where('users.id', $studentId)
            ->first();

        if (!$student) {
            return back()->with(
                'error',
                'Pengajuan mahasiswa tidak ditemukan atau sudah diproses.'
            );
        }

        $course->students()->updateExistingPivot($studentId, [
            'status' => 'rejected',
        ]);

        return back()->with(
            'success',
            'Pengajuan mahasiswa berhasil ditolak.'
        );
    }

    /**
     * Dosen mengeluarkan mahasiswa dari mata kuliah.
     */
    public function unenrollStudent(MataKuliah $course, $studentId)
    {
        $this->authorizeCourseLecturer($course);

        $student = $course
            ->students()
            ->where('users.id', $studentId)
            ->first();

        if (!$student) {
            return back()->with(
                'error',
                'Mahasiswa tidak ditemukan pada mata kuliah ini.'
            );
        }

        $course->students()->detach($studentId);

        return back()->with(
            'success',
            "Mahasiswa berhasil dikeluarkan dari mata kuliah {$course->kode_mk}."
        );
    }

    /**
     * Validasi hak dosen terhadap mata kuliah.
     */
    private function authorizeCourseLecturer(MataKuliah $course): void
    {
        abort_unless(Auth::user()->role === 'dosen', 403);

        abort_unless(
            (int) $course->dosen_id === (int) Auth::id(),
            403
        );
    }
}
