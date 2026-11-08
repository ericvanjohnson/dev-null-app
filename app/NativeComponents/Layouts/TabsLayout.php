<?php

namespace App\NativeComponents\Layouts;

use App\Devnull\Account;
use App\Devnull\Lane;
use App\Devnull\NataNullers\Game;
use App\Icons\Lucide;
use App\NativeComponents\Log;
use Native\Mobile\Edge\Elements\Image;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\Layouts\TabPresentationPlacement;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\System;

/**
 * The top-level sections: Log, Notifications, Remotes and your Profile, with
 * the icons of the web's nav, picked out in the colour you chose for your lane.
 * Notifications and Remotes wear a badge with the unread count devnull last
 * gave. Starred opens from your own profile.
 *
 * On an iPad, or an unfolded phone, wide enough for it, iOS draws the tabs as
 * a column down the leading edge instead (the devnull:side-rail plugin), with
 * Search above them and a round button for a new commit below. Those two are
 * only for the column, and only on screens that can open them.
 */
class TabsLayout extends NativeLayout
{
    /**
     * Each tab's URI. Commits and profiles open beneath the one they were tapped in.
     */
    public const array Tabs = ['/log', '/notifications', '/remotes', '/profile'];

    /**
     * The side rail plugin's presentation.
     */
    public const string SideRail = 'devnull:side-rail';

    /**
     * The smallest window, in points, that gets the side rail. Narrower or
     * shorter than this keeps the tab bar along the bottom.
     */
    public const int WideMinimumWidth = 900;

    public const int WideMinimumHeight = 600;

    /**
     * The icon and "/dev/null" wordmark as the web's MobileHeader draws them, 105 x 28pt.
     * EDGE images load neither SVG nor a per-appearance variant, so there is a PNG for
     * each appearance. It's rendered at @3x of the largest size it's drawn at, 300pt wide,
     * since onboarding draws it at two thirds of the screen width. Everything else
     * scales it down rather than up.
     */
    public const string LightLogo = 'images/logo-light@3x.png';

    public const string DarkLogo = 'images/logo-dark@3x.png';

    /**
     * The logo for the system appearance.
     */
    public static function logo(): string
    {
        return isDark() ? self::DarkLogo : self::LightLogo;
    }

    protected ?string $font = 'semibold';

    public function usesNativeChrome(): bool
    {
        return true;
    }

    /**
     * The Log wears the logo in place of its title, as the web's header does.
     * Holding it down opens Nata Nu//ers, in a build that has it.
     */
    public function navBar(NativeComponent $screen): ?NavBar
    {
        $navBar = NavBar::make()
            ->title($screen->navTitle())
            ->displayMode('inline');

        if ($screen instanceof Log) {
            $logo = Image::make(self::logo())->height(28)->alt('devnull')->ref('wordmark');

            $navBar->titleView(Game::available() ? $logo->onLongPress('playNataNullers') : $logo);
        }

        return $navBar;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        $notifications = Tab::link('Notifications', '/notifications', ios: Lucide::Bell->ios(), android: Lucide::Bell->android());
        $remotes = Tab::link('Remotes', '/remotes', ios: Lucide::MessagesSquare->ios(), android: Lucide::MessagesSquare->android());

        $log = Tab::link('Log', '/log', ios: Lucide::GitCommitVertical->ios(), android: Lucide::GitCommitVertical->android());

        self::badge($notifications, app(Account::class)->unreadNotifications());
        self::badge($remotes, app(Account::class)->unreadRemotes());

        if ($screen instanceof Log) {
            $log->onReselect('backToTopOfLog');
        }

        $tabBar = TabBar::make()
            ->activeColor(self::accent())
            ->labelVisibility('labeled');

        if (System::isIos() && method_exists($screen, 'openSearch')) {
            $tabBar->add(Tab::action('Search', ios: Lucide::Search->ios(), android: Lucide::Search->android())->id('search')->press('openSearch')->presentationOnly());
        }

        $tabBar->add($log)
            ->add($notifications)
            ->add($remotes)
            ->add(Tab::link('Profile', '/profile', ios: Lucide::UserRound->ios(), android: Lucide::UserRound->android()));

        if (! System::isIos()) {
            return $tabBar;
        }

        if (method_exists($screen, 'composeInSheet')) {
            $tabBar->add(Tab::action('Commit', ios: Lucide::Plus->ios(), android: Lucide::Plus->android())->id('commit')->press('composeInSheet')->presentationOnly());
        }

        return $tabBar->presentation(
            self::SideRail,
            ['accent' => self::accent(), 'expandLabel' => 'Expand', 'collapseLabel' => 'Collapse'],
            TabPresentationPlacement::Leading,
            self::WideMinimumWidth,
            self::WideMinimumHeight,
            keepsOnPush: true,
        );
    }

    private static function badge(Tab $tab, int $unread): void
    {
        if ($unread > 0) {
            $tab->badge($unread > 99 ? '99+' : (string) $unread);
        }
    }

    /**
     * Your lane's colour, in its light or dark lift, or your custom colour,
     * or Commit Blue before devnull has said who you are.
     */
    public static function accent(): string
    {
        $primary = (string) theme('primary', '#3b6fe0');

        return (string) theme(Lane::token(app(Account::class)->user()?->lane()), $primary);
    }
}
