<?php

namespace WebRegulate\LaravelAdministration\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Password;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Classes\ConfiguredModeBasedHandlers\MFAHandler;

class WRLAAuthController extends Controller
{
    /**
     * Login view
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function login(Request $request)
    {
        return view(WRLAHelper::getViewPath('auth.login', true));
    }

    /**
     * Login post
     *
     * @return \Illuminate\Http\RedirectResponse|string
     */
    public function loginPost(Request $request)
    {
        // Apply captcha validation if enabled
        $captchaResult = WRLAHelper::applyCaptchaCheck($request);
        if ($captchaResult !== true) {
            if(!$request->has('mfa_code')) {
                $request->validate(
                    ['captcha.error' => 'required|captcha'],
                    ['captcha.error' => 'Captcha validation failed. Please try again.']
                );
            } else {
                $request->validate(
                    ['mfa_code' => 'required'],
                    ['mfa_code.required' => 'MFA code is required']
                );
            }
        }

        // Validate
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Handle MFA if enabled
        $mfaResult = MFAHandler::handleAllWithRedirects($request, 'wrla.login', $request->get('email'), $request->get('password'));
        if(!empty($mfaResult)) {
            return $mfaResult;
        }

        // Attempt login
        if (WRLAHelper::getUserDataModelClass()::attemptLogin($request->get('email'), $request->get('password'), $request->has('remember'))) {
            // If wrla_impersonating_user is in session, forget it
            if ($request->session()->has('wrla_impersonating_user')) {
                $request->session()->forget('wrla_impersonating_user');
            }

            return redirect()->route('wrla.dashboard');
        }

        WRLAHelper::pushAlert('danger', 'Invalid credentials, please try again');
        return redirect()->back()->withInput();
    }

    /**
     * Impersonate / Login as user
     */
    public function impersonateLoginAs(Request $request, int $userId)
    {
        // Get current user data and user id
        $origionalUserId = WRLAHelper::getCurrentUser()?->id;

        // If null, redirect to login
        if ($origionalUserId === null) {
            return redirect()->route('wrla.login');
        }

        // Get user and user data by id
        $user = WRLAHelper::getUserModelClass()::find($userId);
        $userData = WRLAHelper::getUserDataModelClass()::where('user_id', $userId)->first();

        // Check has impersonate permission
        if (! \App\WRLA\User::getPermission(\App\WRLA\User::IMPERSONATE)) {
            WRLAHelper::pushAlert('danger', 'You do not have permission to login as another user.');
            return redirect()->route('wrla.dashboard');
        }

        // Check user exists
        if ($user == null) {
            WRLAHelper::pushAlert('danger', "User with ID `$userId` not found.");
            return redirect()->route('wrla.dashboard');
        }

        // Login as user
        Auth::login($user);

        // Set wrla_impersonating_user in session to original user id
        session()->put('wrla_impersonating_user', $origionalUserId);

        // Set wrla_impersonating_previous_url in session to url before this impersonate request
        session()->put('wrla_impersonating_previous_url', $request->headers->get('referer', route('wrla.dashboard')));

        // If user has admin privilege redirect to dashboard, otherwise redirect to frontend
        if ($userData !== null && $userData->isAdmin()) {
            return redirect()->route('wrla.dashboard');
        } else {
            return redirect('/');
        }
    }

    /**
     * Impersonate / Switch back
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function impersonateSwitchBack(Request $request)
    {
        // If wrla_impersonating_user is not in session, return invalid request
        if (! $request->session()->has('wrla_impersonating_user')) {
            abort(403, 'Invalid request');
        }

        // Get original user id
        $origionalUserId = $request->session()->get('wrla_impersonating_user');

        // Logout current user
        Auth::logout();

        // Login as original user
        Auth::login(WRLAHelper::getUserModelClass()::find($origionalUserId));

        // Forget wrla_impersonating_user from session
        $request->session()->forget('wrla_impersonating_user');

        // Redirect to wrla_impersonating_previous_url or back if not set
        WRLAHelper::pushAlert('success', 'Switched back to your original account.');
        return redirect()->to(
            $request->session()->get('wrla_impersonating_previous_url', route('wrla.dashboard'))
        );
    }

    /**
     * Forgot password view
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function forgotPassword(Request $request)
    {
        return view(WRLAHelper::getViewPath('auth.forgot-password', true));
    }

    /**
     * Forgot password post
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function forgotPasswordPost(Request $request)
    {
        // Apply captcha validation if enabled
        $captchaResult = WRLAHelper::applyCaptchaCheck($request);
        if ($captchaResult !== true) {
            $request->validate(
                ['captcha.error' => 'required|captcha'],
                ['captcha.error' => 'Captcha validation failed. Please try again.']
            );
        }

        // Get wrla user instance
        $userInstance = \App\WRLA\User::make();

        // Get user email validation rule without unique or confirmed
        $forgotPasswordValidation = WRLAHelper::removeRuleFromValidationString(
            ['unique:users,email,', 'confirmed'],
            $userInstance->getValidationRule('email')
        );

        // Validate
        $request->validate([
            'email' => $forgotPasswordValidation,
        ]);

        // Get user
        $user = WRLAHelper::getUserModelClass()::where('email', $request->input('email'))->first();

        // Check user
        if (! $user) {
            WRLAHelper::pushAlert('danger', 'We could not find a user with that email address');
            return redirect()->back()->withInput();
        }

        // Create token and send the reset link to the user
        $token = Password::createToken($user);
        $user->sendPasswordResetNotification($token);

        // Return user with success
        WRLAHelper::pushAlert('success', 'Password reset link has been sent to: <br />'.$user->email);
        return redirect()->route('wrla.login');
    }

    /**
     * Reset password view
     *
     * @param  string  $token
     * @return \Illuminate\Contracts\View\View | \Illuminate\Http\RedirectResponse
     */
    public function resetPassword(Request $request, $email, $token)
    {
        // Get user by email
        $user = WRLAHelper::getUserModelClass()::where('email', $email)->first();

        // Check if token is valid
        if (! Password::tokenExists($user, $token)) {
            WRLAHelper::pushAlert('danger', 'Invalid token');
            return redirect()->route('wrla.login');
        }

        return view(WRLAHelper::getViewPath('auth.reset-password', true), [
            'email' => $user->email,
            'token' => $token,
        ]);
    }

    /**
     * Reset password post
     *
     * @param  string  $token
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resetPasswordPost(Request $request, $token)
    {
        // Get wrla user instance
        $userInstance = \App\WRLA\User::make();

        // Get user email validation rules without unique (Note we do not have to include the id here because we are using a blank user)
        $resetPasswordValidation = WRLAHelper::removeRuleFromValidationString(
            'unique:users,email,',
            $userInstance->getValidationRule('email')
        );

        // Validate
        $request->validate([
            'email' => $resetPasswordValidation,
        ]);

        // Get user
        $user = WRLAHelper::getUserModelClass()::where('email', $request->input('email'))->first();

        // Check user
        if (! $user) {
            WRLAHelper::pushAlert('danger', 'We could not find a user with that email address');
            return redirect()->back()->withInput();
        }

        // Reset password
        $user->password = Hash::make($request->input('password'));
        $user->save();

        // Return user with success
        WRLAHelper::pushAlert('success', 'Password has been reset successfully');
        return redirect()->route('wrla.login')->withInput();
    }

    /**
     * logout
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        // Logout
        Auth::logout();

        // If wrla_impersonating_user is in session, forget it
        if ($request->session()->has('wrla_impersonating_user')) {
            $request->session()->forget('wrla_impersonating_user');
        }

        // Redirect to login
        if(config('wr-laravel-administration.wrla_auth_routes_enabled')) {
            return redirect()->route('wrla.login');
        } {
            return redirect('/');
        }
    }
}
