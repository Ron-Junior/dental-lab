<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['fileable_type', 'fileable_id', 'name', 'path', 'disk', 'mime_type', 'extension', 'size', 'original_size'])]
class File extends Model
{
    use HasFactory;

    protected $casts = [
        'size' => 'integer',
        'original_size' => 'integer',
    ];

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function url(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! $this->path) {
                    return null;
                }

                $disk = Storage::disk($this->disk ?: config('filesystems.default', 's3'));

                if (method_exists($disk, 'temporaryUrl')) {
                    return $disk->temporaryUrl($this->path, now()->addHours(1));
                }

                return $disk->url($this->path);
            }
        );
    }

    protected function formattedSize(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (! $this->size) {
                    return '0 B';
                }

                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $power = $this->size > 0 ? (int) floor(log($this->size, 1024)) : 0;
                $power = min($power, count($units) - 1);

                return number_format($this->size / pow(1024, $power), 2, ',', '.').' '.$units[$power];
            }
        );
    }

    protected function formattedOriginalSize(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (! $this->original_size) {
                    return '0 B';
                }

                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $power = $this->original_size > 0 ? (int) floor(log($this->original_size, 1024)) : 0;
                $power = min($power, count($units) - 1);

                return number_format($this->original_size / pow(1024, $power), 2, ',', '.').' '.$units[$power];
            }
        );
    }
}
