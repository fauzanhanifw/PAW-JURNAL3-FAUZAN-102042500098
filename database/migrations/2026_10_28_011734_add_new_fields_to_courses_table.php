
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hasKodeMK = Schema::hasColumn('courses', 'kode_mk');
        $hasNamaMK = Schema::hasColumn('courses', 'nama_mk');
        $hasSKS = Schema::hasColumn('courses', 'sks');
        $hasSemester = Schema::hasColumn('courses', 'semester');

        Schema::table('courses', function (Blueprint $table) use (
            $hasKodeMK,
            $hasNamaMK,
            $hasSKS,
            $hasSemester
        ) {
            if (!$hasKodeMK) {
                $table->string('kode_mk', 20)->nullable();
            }

            if (!$hasNamaMK) {
                $table->string('nama_mk')->nullable();
            }

            if (!$hasSKS) {
                $table->unsignedTinyInteger('sks')->nullable();
            }

            if (!$hasSemester) {
                $table->string('semester', 50)->nullable();
            }
        });

        if (Schema::hasColumn('courses', 'kode_mata_kuliah')) {
            DB::statement(
                'UPDATE courses
                 SET kode_mk = kode_mata_kuliah
                 WHERE kode_mk IS NULL'
            );
        }

        if (Schema::hasColumn('courses', 'nama')) {
            DB::statement(
                'UPDATE courses
                 SET nama_mk = nama
                 WHERE nama_mk IS NULL'
            );
        }

        DB::table('courses')
            ->whereNull('sks')
            ->update(['sks' => 3]);

        DB::table('courses')
            ->whereNull('semester')
            ->update(['semester' => 'Semester 1']);

        if (!Schema::hasIndex('courses', 'courses_kode_mk_unique')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->unique('kode_mk', 'courses_kode_mk_unique');
            });
        }
    }

    public function down(): void
    {
        // Tidak menghapus kolom atau data saat rollback.
    }
};
