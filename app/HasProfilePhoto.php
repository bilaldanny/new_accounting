<?php

namespace App;

use Illuminate\Support\Facades\Storage;

trait HasProfilePhoto
{
    /**
     * Get the URL to the user's profile photo.
     */
    public function getProfilePhotoUrlAttribute()
    {
        if (! $this->profile_photo_path) {
            return $this->defaultProfilePhotoUrl();
        }

        // Normalize legacy values like "storage/photos/..." or "storage/storage/photos/..."
        $normalizedPath = ltrim((string) $this->profile_photo_path, '/');
        $normalizedPath = (string) preg_replace('#^(storage/)+#i', '', $normalizedPath);

        $storageUrl = Storage::url($normalizedPath);
        if (preg_match('#^https?://#i', (string) $storageUrl)) {
            return $storageUrl;
        }

        $base = rtrim((string) config('app.url', url('/')), '/');
        $base = (string) preg_replace('#/storage/?$#i', '', $base);
        $relative = '/'.ltrim((string) $storageUrl, '/');
        $absolute = $base.$relative;

        // Final safety: collapse accidental duplicate "/storage/storage/" once.
        return (string) preg_replace('#/storage/+storage/#i', '/storage/', $absolute);
    }

    /**
     * Get the default profile photo URL if no photo has been uploaded.
     */
    protected function defaultProfilePhotoUrl()
    {
        return 'https://ui-avatars.com/api/?name='.urlencode($this->fullname).'&color=ffffff&background=34886a';
    }
}
