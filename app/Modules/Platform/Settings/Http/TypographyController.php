<?php

namespace App\Modules\Platform\Settings\Http;

use App\Core\Typography\TypographyResolverService;
use App\Platform\Models\PlatformFont;
use App\Platform\Models\PlatformFontFamily;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TypographyController
{
    public function show(): JsonResponse
    {
        return response()->json([
            'typography' => TypographyResolverService::forPlatform(),
            'families' => PlatformFontFamily::with('fonts')
                ->where('source', 'local')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'active_source' => 'required|in:local,google',
            'active_family_id' => 'nullable|integer|exists:platform_font_families,id',
            'google_fonts_enabled' => 'required|boolean',
            'google_active_family' => 'nullable|string|max:100',
            'google_weights' => 'required|array|min:1',
            'google_weights.*' => 'integer|in:100,200,300,400,500,600,700,800,900',
            'headings_family_id' => 'nullable|integer|exists:platform_font_families,id',
            'body_family_id' => 'nullable|integer|exists:platform_font_families,id',
        ]);

        $validated['google_fonts_enabled'] = (bool) $validated['google_fonts_enabled'];

        // Guard: if Google Fonts disabled, force source to local
        if (! $validated['google_fonts_enabled'] && $validated['active_source'] === 'google') {
            $validated['active_source'] = 'local';
        }

        DB::transaction(function () use ($validated) {
            $settings = PlatformSetting::instance();
            $settings->update(['typography' => $validated]);
        });

        return response()->json([
            'typography' => TypographyResolverService::forPlatform(),
            'message' => 'Typography settings updated.',
        ]);
    }

    public function createFamily(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $slug = Str::slug($validated['name']);

        if (PlatformFontFamily::where('slug', $slug)->exists()) {
            return response()->json([
                'message' => 'A font family with this name already exists.',
            ], 422);
        }

        $family = PlatformFontFamily::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'source' => 'local',
            'is_enabled' => true,
        ]);

        return response()->json([
            'family' => $family->load('fonts'),
            'message' => 'Font family created.',
        ], 201);
    }

    public function uploadFont(Request $request, int $familyId): JsonResponse
    {
        $family = PlatformFontFamily::where('source', 'local')->findOrFail($familyId);

        $request->validate([
            'font' => ['required', 'file', 'max:2048'],
            'weight' => 'required|integer|in:100,200,300,400,500,600,700,800,900',
            'style' => 'required|in:normal,italic',
        ]);

        $file = $request->file('font');

        if (strtolower($file->getClientOriginalExtension()) !== 'woff2') {
            return response()->json([
                'message' => 'Only .woff2 files are accepted.',
            ], 422);
        }

        $weight = (int) $request->input('weight');
        $style = $request->input('style');

        // Check for existing variant (upsert)
        $existing = PlatformFont::where('family_id', $familyId)
            ->where('weight', $weight)
            ->where('style', $style)
            ->first();

        if ($existing) {
            Storage::disk('public')->delete($existing->file_path);
        }

        $sha256 = hash_file('sha256', $file->getRealPath());
        $path = $file->store("fonts/{$family->slug}", 'public');

        if ($existing) {
            $existing->update([
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'sha256' => $sha256,
            ]);
            $font = $existing->fresh();
        } else {
            $font = PlatformFont::create([
                'family_id' => $familyId,
                'weight' => $weight,
                'style' => $style,
                'format' => 'woff2',
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'sha256' => $sha256,
            ]);
        }

        return response()->json([
            'font' => $font,
            'family' => $family->load('fonts'),
            'message' => 'Font variant uploaded.',
        ], 201);
    }

    public function deleteFont(int $familyId, int $fontId): JsonResponse
    {
        $font = PlatformFont::where('family_id', $familyId)->findOrFail($fontId);

        Storage::disk('public')->delete($font->file_path);
        $font->delete();

        return response()->json([
            'family' => PlatformFontFamily::with('fonts')->find($familyId),
            'message' => 'Font variant deleted.',
        ]);
    }

    public function deleteFamily(int $familyId): JsonResponse
    {
        $family = PlatformFontFamily::findOrFail($familyId);

        // Prevent deleting the active family
        $settings = PlatformSetting::instance()->typography ?? [];
        if (($settings['active_family_id'] ?? null) == $familyId) {
            return response()->json([
                'message' => 'Cannot delete the currently active font family. Switch to another family first.',
            ], 409);
        }

        // Delete all font files from disk
        foreach ($family->fonts as $font) {
            Storage::disk('public')->delete($font->file_path);
        }

        $family->delete();

        return response()->json([
            'message' => 'Font family deleted.',
        ]);
    }
}
