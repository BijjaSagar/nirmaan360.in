<?php

namespace App\Services;

use App\Models\Project;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A project's own photo, kept on the private disk and read only through a checked route.
 *
 * A photo from a phone is several megabytes and the project list shows many at once, often on
 * site mobile data, so it is stored as a smaller JPEG. Saving it that way also drops the
 * location the phone wrote into the file.
 */
class ProjectImageService
{
    private const LONGEST_EDGE = 1600;

    private const JPEG_QUALITY = 82;

    /** A very large picture would use more memory to open than the app has. */
    private const MAX_PIXELS = 40_000_000;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    /** Put a new photo in place of the old one, or take the photo off the project. A new photo wins over removing. */
    public function save(Project $project, ?UploadedFile $file, bool $remove): void
    {
        if ($file === null && ! $remove) {
            return;
        }

        $old = $project->image_path;
        $new = $file === null ? null : $this->store($project, $file);

        $project->update(['image_path' => $new]);

        if ($old !== null && $old !== $new) {
            Storage::delete($old);
        }
    }

    private function store(Project $project, UploadedFile $file): string
    {
        $folder = 'org-'.$project->organization_id.'/projects/'.$project->id;
        $jpeg = $this->smaller($file);
        $path = $folder.'/'.Str::random(40).'.jpg';

        // When the picture cannot be redrawn the original is kept, which still shows correctly.
        $stored = $jpeg !== null && Storage::put($path, $jpeg) ? $path : $file->store($folder);

        return is_string($stored) ? $stored : throw ValidationException::withMessages(['image' => __('The photo could not be saved. Please try again.')]);
    }

    /** The picture as an upright JPEG no longer than LONGEST_EDGE, or null when it cannot be redrawn. */
    private function smaller(UploadedFile $file): ?string
    {
        try {
            $size = @getimagesize($file->getRealPath());

            // Without reading the orientation, a redrawn phone photo could come out sideways.
            if (! $size || $size[0] * $size[1] > self::MAX_PIXELS || ! function_exists('exif_read_data')) {
                return null;
            }

            $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

            if (! $source) {
                return null;
            }

            $source = $this->upright($source, $file);
            [$width, $height] = [imagesx($source), imagesy($source)];
            $scale = min(1, self::LONGEST_EDGE / max($width, $height));
            $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

            // JPEG has no transparency, so a clear background becomes white rather than black.
            imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

            ob_start();
            imagejpeg($target, null, self::JPEG_QUALITY);

            return (string) ob_get_clean();
        } catch (Throwable) {
            return null;
        }
    }

    /** Phones save a photo as the sensor saw it and write which way up it was held. */
    private function upright(GdImage $image, UploadedFile $file): GdImage
    {
        $angle = match ((int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1)) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : imagerotate($image, $angle, 0);
    }
}
