<?php

namespace App\Models;

use App\Repositories\Daemon\DaemonFileRepository;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Sushi\Sushi;

/**
 * @property string $name
 * @property Carbon $created
 * @property Carbon $modified
 * @property string $mode
 * @property int $mode_bits
 * @property int $size
 * @property bool $is_directory
 * @property bool $is_file
 * @property bool $is_symlink
 * @property string $mime_type
 */
class File extends Model
{
    use Sushi;

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    public const ARCHIVE_MIMES = [
        'application/vnd.rar', // .rar
        'application/x-rar-compressed', // .rar (2)
        'application/x-tar', // .tar
        'application/x-br', // .tar.br
        'application/x-bzip2', // .tar.bz2, .bz2
        'application/gzip', // .tar.gz, .gz
        'application/x-gzip',
        'application/x-lzip', // .tar.lz4, .lz4 (not sure if this mime type is correct)
        'application/x-sz', // .tar.sz, .sz (not sure if this mime type is correct)
        'application/x-xz', // .tar.xz, .xz
        'application/x-7z-compressed', // .7z
        'application/zstd', // .tar.zst, .zst
        'application/zip', // .zip
    ];

    protected static Server $server;
    protected static string $path;

    public static function get(Server $server, string $path = '/'): Builder
    {
        self::$server = $server;
        self::$path = $path;

        return self::query();
    }

    public function isArchive(): bool
    {
        return $this->is_file && in_array($this->mime_type, self::ARCHIVE_MIMES);
    }

    public function getIcon(): string
    {
        if ($this->is_directory) {
            return 'tabler-folder';
        }

        if ($this->isArchive()) {
            return 'tabler-file-zip';
        }

        return $this->is_symlink ? 'tabler-file-symlink' : 'tabler-file';
    }

    public function canEdit(): bool
    {
        if ($this->is_directory || $this->isArchive() || $this->is_symlink) {
            return false;
        }

        return $this->is_file && !in_array($this->mime_type, ['application/jar', 'application/octet-stream', 'inode/directory']);
    }

    public function server(): Server
    {
        return self::$server;
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'modified_at' => 'datetime',
        ];
    }

    public function getSchema(): array
    {
        return [
            'name' => 'string',
            'created_at' => 'string',
            'modified_at' => 'string',
            'mode' => 'string',
            'mode_bits' => 'integer',
            'size' => 'integer',
            'is_directory' => 'boolean',
            'is_file' => 'boolean',
            'is_symlink' => 'boolean',
            'mime_type' => 'string',
        ];
    }

    public function getRows(): array
    {
        try {
            $contents = app(DaemonFileRepository::class)
                ->setServer($this->server())
                ->getDirectory(self::$path ?? '/');

            if (isset($contents['error'])) {
                throw new Exception($contents['error']);
            }

            return array_map(function ($file) {
                return [
                    'name' => $file['name'],
                    'created_at' => Carbon::parse($file['created']),
                    'modified_at' => Carbon::parse($file['modified']),
                    'mode' => $file['mode'],
                    'mode_bits' => (int) $file['mode_bits'],
                    'size' => (int) $file['size'],
                    'is_directory' => $file['directory'],
                    'is_file' => $file['file'],
                    'is_symlink' => $file['symlink'],
                    'mime_type' => $file['mime'],
                ];
            }, $contents);
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title('Error loading files')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return [];
        }
    }

    protected function sushiShouldCache(): bool
    {
        return false;
    }
}
