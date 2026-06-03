<?php

namespace App\Imports;

use App\Jobs\ProcessSetupFilesJob;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Hash;
use Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterImport;

class StudentsImport implements ToCollection, WithChunkReading, WithEvents, ShouldQueue
{
    use Importable;

    /**
     * @param int $setupFileId  Passed so AfterImport can dispatch the Gemini job
     *                          only after ALL chunks have finished.
     */
    public function __construct(private readonly int $setupFileId) {}

    // ── Events ────────────────────────────────────────────────────────────────

    public function registerEvents(): array
    {
        return [
            // Fired once after every chunk job completes — i.e. the whole import is done.
            AfterImport::class => function (AfterImport $event) {
                ProcessSetupFilesJob::dispatch($this->setupFileId);
                Log::info('StudentsImport: all chunks done — ProcessSetupFilesJob dispatched', [
                    'setup_id' => $this->setupFileId,
                ]);
            },
        ];
    }

    public function collection(Collection $rows)
    {
        $lines = [];

        foreach ($rows as $row) {
            $cells = array_values(array_filter(
                array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row->toArray()),
                fn ($v) => $v !== null && $v !== ''
            ));

            foreach ($cells as $cell) {
                $lines[] = $cell;
            }
        }

        $current = [
            'name' => null,
            'code' => null,
            'national_id' => null,
            'gpa' => null,
            'level' => null,
        ];

        for ($i = 0; $i < count($lines); $i++) {
            $value = $lines[$i];

            if ($value === 'اسم الطالب' && $i > 0) {
                $current['name'] = $lines[$i - 1];
                continue;
            }

            if ($value === 'كود الطالب' && $i > 0) {
                $prev = $lines[$i - 1];
                if (preg_match('/^\d{10,20}$/', (string)$prev)) {
                    $current['code'] = $prev;
                }
                continue;
            }

            if ($value === 'الرقم القومى' && $i > 0) {
                $post = $lines[$i + 1];
                for ($j = $i - 1; $j >= max(0, $i - 5); $j--) {
                    if (preg_match('/^\d{14}$/', (string)$post)) {
                        $current['national_id'] = $post;
                        break;
                    }
                }
                continue;
            }

            if (str_contains($value, 'المستوى الحالى')) {
                preg_match('/المستوي\s+([^\s\/]+)/u', $value, $m);
                $current['level'] = "المستوي " . ($m[1] ?? null);
                continue;
            }

            if ($current['gpa'] === null && is_numeric($value) && (float)$value <= 4) {
                $current['gpa'] = (string) $value;
            }
        }
        $this->saveStudent($current);
    }

    protected function saveStudent(array $student): void
    {
        if (empty($student['code']) && empty($student['national_id'])) {
            return;
        }

        User::updateOrCreate(
            ['code' => $student['code']],
            [
                'name' => $student['name'],
                'national_id' => $student['national_id'],
                'gpa' => $student['gpa'],
                'level' => $student['level'],
                'password' => !empty($student['national_id']) ? Hash::make($student['national_id']) : null,
                'role' => 'student',
            ]
        );
    }

    public function chunkSize(): int
    {
        return 100;
    }
}