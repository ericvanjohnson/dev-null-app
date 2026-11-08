<?php

use App\Models\PendingWrite;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Victorycodedev\ToastKit\Events\ToastActionPressed;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    Http::fake([
        'devnull.test/api/v1/commits/c1' => Http::response(commitPageJson(commitJson(['id' => 'c1']))),
        'devnull.test/*' => Http::response(pageJson([])),
    ]);
});

/**
 * The props of the screen's root tabs.
 *
 * @return array<string, mixed>
 */
function rootTabsProps(TestableComponent $screen): array
{
    return findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'native_root_tabs')['props'];
}

/**
 * Each bottom nav item's props, in order.
 *
 * @return list<array<string, mixed>>
 */
function navItems(TestableComponent $screen): array
{
    return array_column(findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_nav_item'), 'props');
}

it('selects the side rail on iOS', function () {
    $props = rootTabsProps(Native::visit('/log', platform: 'ios'));

    expect($props)->toMatchArray([
        'tab_presentation_id' => 'devnull:side-rail',
        'tab_presentation_placement' => 'leading',
        'tab_presentation_min_width' => TabsLayout::WideMinimumWidth,
        'tab_presentation_min_height' => TabsLayout::WideMinimumHeight,
        'tab_presentation_keeps_on_push' => true,
    ]);

    $configuration = json_decode($props['tab_presentation_configuration'], true);

    expect($configuration)->toMatchArray([
        'accent' => TabsLayout::accent(),
        'expandLabel' => 'Expand',
        'collapseLabel' => 'Collapse',
    ])->and($configuration['accent'])->toMatch('/^#([0-9a-f]{6}|[0-9a-f]{8})$/i');
});

it('leaves Android alone', function () {
    $screen = Native::visit('/log', platform: 'android');

    expect(rootTabsProps($screen))->not->toHaveKey('tab_presentation_id')
        ->and(array_filter(navItems($screen), fn (array $item): bool => $item['presentation_only'] ?? false))->toBeEmpty();
});

it('keeps the four real tabs first and adds Search and the commit button after them, presenter-only', function (string $root) {
    $items = navItems(Native::visit($root, platform: 'ios'));

    expect(array_column($items, 'id'))->toBe(['log', 'notifications', 'remotes', 'profile', 'search', 'commit'])
        ->and($items[4])->toMatchArray(['label' => 'Search', 'icon' => 'custom:search', 'presentation_only' => true])
        ->and($items[5])->toMatchArray(['label' => 'New commit', 'presentation_only' => true])
        ->and(array_filter(array_slice($items, 0, 4), fn (array $item): bool => $item['presentation_only'] ?? false))->toBeEmpty();
})->with(TabsLayout::Tabs);

it('opens search and the compose sheet from every tab root', function (string $root) {
    Native::visit($root, platform: 'ios')->press('openSearch')->assertNavigatedTo("{$root}/search");

    $screen = Native::visit($root, platform: 'ios')->press('composeInSheet')
        ->assertNoNavigation()
        ->assertSee('New commit');

    expect(nodeByRef($screen, 'sheet')['props'])->toMatchArray(['visible' => true]);
})->with(TabsLayout::Tabs);

it('drops items a pushed screen cannot handle, without moving the real tabs', function () {
    // This device has neither search nor a composer.
    expect(array_column(navItems(Native::visit('/profile/device', platform: 'ios')), 'id'))
        ->toBe(['log', 'notifications', 'remotes', 'profile']);

    // A commit's page has both.
    expect(array_column(navItems(Native::visit('/log/commits/c1', platform: 'ios')), 'id'))
        ->toBe(['log', 'notifications', 'remotes', 'profile', 'search', 'commit']);
});

it('keeps the real tabs at the same positions whether or not the screen can search', function () {
    $withSearch = array_column(navItems(Native::visit('/profile', platform: 'ios')), 'id');
    $withoutSearch = array_column(navItems(Native::visit('/profile/device', platform: 'ios')), 'id');

    expect($withSearch)->toContain('search')
        ->and($withoutSearch)->not->toContain('search')
        ->and(array_slice($withSearch, 0, 4))->toBe(array_slice($withoutSearch, 0, 4));
});

it('opens the commit a turned-down quote or reply was for from Notifications and Remotes, which show no commits', function (string $root, string $composer, string $key) {
    $write = PendingWrite::query()->create([
        'user_id' => userJson()['id'],
        'kind' => PendingWrite::Commit,
        'client_id' => (string) Str::uuid(),
        'payload' => ['body' => 'This, all of it.', 'composer' => $composer, $key => 'c9'],
        'status' => PendingWrite::Failed,
        'attempts' => 1,
    ]);

    Native::visit($root, platform: 'ios')
        ->emitNative(ToastActionPressed::class, ['toastId' => 'toast-1', 'actionId' => 'edit', 'data' => ['write' => $write->id]])
        ->assertNavigatedTo("{$root}/commits/c9");
})->with(['/notifications', '/remotes'])->with([
    'quote' => ['quote', 'quoted_commit_id'],
    'reply' => ['reply', 'parent_id'],
]);
