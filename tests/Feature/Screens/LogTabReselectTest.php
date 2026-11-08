<?php

use App\Devnull\Data\Commit;
use App\NativeComponents\Log;
use Native\Mobile\Testing\Native;

it('keeps the selected feed, its commits and pagination when returning to its top', function (string $feed, string $platform) {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    holdRefreshes();
    keep('log/'.$feed, [
        ...pageJson([
            commitJson(['id' => 'c-newest', 'body' => 'The newest loaded commit']),
            commitJson(['id' => 'c-older', 'body' => 'An older loaded commit']),
        ], nextCursor: 'next-page', path: 'http://devnull.test/api/v1/log/'.$feed),
        'is_ranked' => $feed === 'trending',
        'newest_commit_id' => 'c-newest',
    ]);

    $screen = Native::visit('/log', platform: $platform);
    tapLogTab($screen, $feed);
    $list = showingLog($screen)['id'];
    $requests = refreshesSent($screen);

    $screen->changeTab('backToTop', (int) array_search($feed, Log::Logs, true))
        ->assertSet('log', $feed)
        ->assertSet('nextCursor', 'next-page')
        ->assertNoNavigation();

    expect(showingLog($screen)['id'])->not->toBe($list);
    expect(array_map(fn (Commit $commit): string => $commit->id, $screen->get('commits')))
        ->toBe(['c-newest', 'c-older']);
    expect(refreshesSent($screen))->toBe($requests);
})->with(Log::Logs)->with(['ios', 'android']);

it('takes the log you are on back to its top when its bottom tab is tapped again', function (string $platform) {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    holdRefreshes();
    keep('log/latest', [
        ...pageJson([
            commitJson(['id' => 'c-newest', 'body' => 'The newest loaded commit']),
        ], nextCursor: 'next-page', path: 'http://devnull.test/api/v1/log/latest'),
        'newest_commit_id' => 'c-newest',
    ]);

    $screen = Native::visit('/log', platform: $platform);
    tapLogTab($screen, 'latest');
    $list = showingLog($screen)['id'];

    $screen->press('backToTopOfLog')
        ->assertSet('log', 'latest')
        ->assertSet('nextCursor', 'next-page')
        ->assertNoNavigation();

    expect(showingLog($screen)['id'])->not->toBe($list);
})->with(['ios', 'android']);
