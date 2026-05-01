<?php

namespace Database\Seeders;

use App\Core\Documentation\DocumentationArticle;
use App\Core\Documentation\DocumentationGroup;
use App\Core\Documentation\DocumentationTopic;
use Illuminate\Database\Seeder;

/**
 * Seeds the Help Center with structured content for 3 audiences:
 * - public: conversion-oriented content for prospects
 * - company: user guide for SaaS clients
 * - platform: governance/operations guide for admins
 *
 * Idempotent: skips if content already exists.
 * Data files in database/seeders/data/help-center-*.php
 */
class HelpCenterSeeder extends Seeder
{
    public function run(): void
    {
        // Skip if content already exists (idempotent)
        if (DocumentationGroup::count() > 0) {
            $this->command?->info('Help Center content already exists — skipping.');

            return;
        }

        $audiences = ['public', 'company', 'platform'];

        foreach ($audiences as $audience) {
            $data = require __DIR__.'/data/help-center-'.$audience.'.php';
            $this->seedAudience($audience, $data);
        }

        $totalTopics = DocumentationTopic::count();
        $totalArticles = DocumentationArticle::count();
        $this->command?->info("Help Center seeded: {$totalTopics} topics, {$totalArticles} articles.");
    }

    private function seedAudience(string $audience, array $data): void
    {
        // Create group
        $group = DocumentationGroup::create([
            'title' => $data['group']['title'],
            'slug' => $data['group']['slug'],
            'icon' => $data['group']['icon'],
            'audience' => $audience,
            'is_published' => true,
            'sort_order' => match ($audience) {
                'public' => 0,
                'company' => 1,
                'platform' => 2,
            },
        ]);

        // Create topics and articles
        foreach ($data['topics'] as $sortOrder => $topicData) {
            $topic = DocumentationTopic::create([
                'title' => $topicData['title'],
                'slug' => $topicData['slug'],
                'description' => $topicData['description'],
                'icon' => $topicData['icon'],
                'group_id' => $group->id,
                'audience' => $audience,
                'is_published' => true,
                'sort_order' => $sortOrder,
            ]);

            foreach ($topicData['articles'] as $articleSort => $articleData) {
                DocumentationArticle::create([
                    'topic_id' => $topic->id,
                    'title' => $articleData['title'],
                    'slug' => $articleData['slug'],
                    'excerpt' => $articleData['excerpt'],
                    'content' => $articleData['content'],
                    'audience' => $audience,
                    'is_published' => true,
                    'sort_order' => $articleSort,
                ]);
            }
        }

        $this->command?->info("  → {$audience}: {$data['group']['title']} seeded.");
    }
}
