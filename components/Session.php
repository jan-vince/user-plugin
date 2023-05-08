<?php namespace RainLab\User\Components;

use Lang;
use Auth;
use Event;
use Flash;
use Request;
use Redirect;
use Cms\Classes\Page;
use Cms\Classes\ComponentBase;
use RainLab\User\Models\UserGroup;
use SystemException;

/**
 * Session component
 *
 * This will inject the user object to every page and provide the ability for
 * the user to sign out. This can also be used to restrict access to pages.
 */
class Session extends ComponentBase
{
    const ALLOW_ALL = 'all';
    const ALLOW_GUEST = 'guest';
    const ALLOW_USER = 'user';

    /**
     * componentDetails
     */
    public function componentDetails()
    {
        return [
            'name' => 'rainlab.user::lang.session.session',
            'description' => 'rainlab.user::lang.session.session_desc'
        ];
    }

    /**
     * defineProperties
     */
    public function defineProperties()
    {
        return [
            'security' => [
                'title' => 'rainlab.user::lang.session.security_title',
                'description' => 'rainlab.user::lang.session.security_desc',
                'type' => 'dropdown',
                'default' => 'all',
                'options' => [
                    'all' => 'rainlab.user::lang.session.all',
                    'user' => 'rainlab.user::lang.session.users',
                    'guest' => 'rainlab.user::lang.session.guests'
                ]
            ],
            'allowedUserGroups' => [
                'title' => 'rainlab.user::lang.session.allowed_groups_title',
                'description' => 'rainlab.user::lang.session.allowed_groups_description',
                'placeholder' => '*',
                'type' => 'set',
                'default' => []
            ],
            'redirect' => [
                'title' => 'rainlab.user::lang.session.redirect_title',
                'description' => 'rainlab.user::lang.session.redirect_desc',
                'type' => 'dropdown',
                'default' => ''
            ],
            'verifyToken' => [
                'title' => /*Use token authentication*/'rainlab.user::lang.session.verify_token',
                'description' => /*Check authentication using a verified bearer token.*/'rainlab.user::lang.session.verify_token_desc',
                'type' => 'checkbox',
                'default' => 0
            ],
        ];
    }

    /**
     * getRedirectOptions
     */
    public function getRedirectOptions()
    {
        return [''=>'- none -'] + Page::sortBy('baseFileName')->lists('baseFileName', 'baseFileName');
    }

    /**
     * getAllowedUserGroupsOptions
     */
    public function getAllowedUserGroupsOptions()
    {
        return UserGroup::lists('name','code');
    }

    /**
     * init component
     */
    public function init()
    {
        // Login with token
        if ($this->property('verifyToken', false)) {
            $this->authenticateWithBearerToken();
        }

        // Inject security logic pre-AJAX
        $this->controller->bindEvent('page.init', function() {
            if (Request::ajax() && ($redirect = $this->checkUserSecurityRedirect())) {
                return ['X_OCTOBER_REDIRECT' => $redirect->getTargetUrl()];
            }
        });
    }

    /**
     * Executed when this component is bound to a page or layout.
     */
    public function onRun()
    {
        if ($redirect = $this->checkUserSecurityRedirect()) {
            return $redirect;
        }

        $this->page['user'] = $this->user();
    }

    /**
     * user returns the logged in user, if available, and touches
     * the last seen timestamp.
     * @return RainLab\User\Models\User
     */
    public function user()
    {
        if (!$user = Auth::getUser()) {
            return null;
        }

        if (!Auth::isImpersonator()) {
            $user->touchLastSeen();
        }

        return $user;
    }

    /**
     * token returns an authentication token
     */
    public function token()
    {
        return Auth::getBearerToken();
    }

    /**
     * Returns the previously signed in user when impersonating.
     */
    public function impersonator()
    {
        return Auth::getImpersonator();
    }

    /**
     * onLogout logs out the user
     *
     * Usage:
     *   <a data-request="onLogout">Sign out</a>
     *
     * With the optional redirect parameter:
     *   <a data-request="onLogout" data-request-data="redirect: '/good-bye'">Sign out</a>
     *
     */
    public function onLogout()
    {
        $user = Auth::getUser();

        Auth::logout();

        if ($user) {
            Event::fire('rainlab.user.logout', [$user]);
        }

        $url = post('redirect', Request::fullUrl());

        Flash::success(Lang::get('rainlab.user::lang.session.logout'));

        return Redirect::to($url);
    }

    /**
     * If impersonating, revert back to the previously signed in user.
     * @return Redirect
     */
    public function onStopImpersonating()
    {
        if (!Auth::isImpersonator()) {
            return $this->onLogout();
        }

        Auth::stopImpersonate();

        $url = post('redirect', Request::fullUrl());

        Flash::success(Lang::get('rainlab.user::lang.session.stop_impersonate_success'));

        return Redirect::to($url);
    }

    /**
     * checkUserSecurityRedirect will return a redirect if the user cannot access the page.
     */
    protected function checkUserSecurityRedirect()
    {
        // No security layer enabled
        if ($this->checkUserSecurity()) {
            return;
        }

        if (!$this->property('redirect')) {
            throw new SystemException('Redirect property is empty on Session component.');
        }

        $redirectUrl = $this->controller->pageUrl($this->property('redirect'));

        return Redirect::guest($redirectUrl);
    }

    /**
     * checkUserSecurity checks if the user can access this page based on the security rules.
     */
    protected function checkUserSecurity(): bool
    {
        $allowedGroup = $this->property('security', self::ALLOW_ALL);
        $allowedUserGroups = (array) $this->property('allowedUserGroups', []);
        $isAuthenticated = Auth::check();

        if ($isAuthenticated) {
            if ($allowedGroup == self::ALLOW_GUEST) {
                return false;
            }

            if (!empty($allowedUserGroups)) {
                $userGroups = Auth::getUser()->groups->lists('code');
                if (!count(array_intersect($allowedUserGroups, $userGroups))) {
                    return false;
                }
            }
        }
        else {
            if ($allowedGroup == self::ALLOW_USER) {
                return false;
            }
        }

        return true;
    }

    /**
     * authenticateWithBearerToken
     */
    protected function authenticateWithBearerToken()
    {
        if ($jwtToken = Request::bearerToken()) {
            Auth::checkBearerToken($jwtToken);
        }
    }
}
