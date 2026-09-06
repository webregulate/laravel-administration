<?php
namespace WebRegulate\LaravelAdministration\Classes\ConfiguredModeBasedHandlers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use PragmaRX\Google2FAQRCode\Google2FA;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Classes\ConfiguredModeBasedHandler;

class MFAHandler extends ConfiguredModeBasedHandler
{
    /**
     * Base configuration path
     */
    public function baseConfigurationPath(): string {
        return 'mfa';
    }

    /**
     * Handle all with redirects
     */
    public static function handleAllWithRedirects(Request $request, string $useRoute, string $email, string $password): mixed
    {
        $mfaHandler = new MFAHandler();

        // If MFA is not in use, return null
        if(!$mfaHandler->isEnabled()) {
            return null;
        }

        // Get user and user data
        $user = WRLAHelper::getUserDataModelClass()::getUserByEmail($email);

        // If user doesn't exist, redirect back with error
        if ($user === null) {
            WRLAHelper::pushAlert('danger', 'Invalid credentials, please try again');
            return redirect()->route($useRoute)->withInput();
        }

        // If password is incorrect, redirect back with error
        if (!WRLAHelper::getUserDataModelClass()::checkPassword($email, $password)) {
            WRLAHelper::pushAlert('danger', 'Invalid credentials, please try again');
            return redirect()->route($useRoute)->withInput();
        }

        // Get wrla user data
        $wrlaUserData = $user->wrlaUserData;

        // If wrla user data does not require MFA, return null
        if (empty($wrlaUserData) || $wrlaUserData->user_id == null || !$wrlaUserData->requiresMFA()) {
            return null;
        }

        // Get user's MFA secret key
        $secretKey = $wrlaUserData->getMFASecretKey();

        /* User does not yet have their secret key set and no MFA code passed
        -----------------------------------------------------------------*/
        if(empty($secretKey) && !$request->has('mfa_code')) {
            if(!session()->has('mfa_initial_setup')) {
                // Generate secret key and QR image
                $secretAndQrImage = $mfaHandler->generateSecretAndQRImage($email);
    
                // Store in session incase user cancels or fails to complete the setup
                $request->session()->put('mfa_initial_setup', $secretAndQrImage);
            } else {
                // Get secret key and QR image from session
                $secretAndQrImage = $request->session()->get('mfa_initial_setup');
            }

            // Render 2FA initial setup
            return redirect()->route($useRoute)->with([
                'mfa' => $mfaHandler->render2FAFormInitialSetup($email, $password, $secretAndQrImage['qrImage'], $secretAndQrImage['secretKey']),
            ]);
        }
        /* User does not yet have their secret key set but has passed an MFA code
        ---------------------------------------------------------------*/
        elseif(empty($secretKey) && $request->has('mfa_code')) {
            // Get mfa code and secret key from request
            $mfaCode = $request->get('mfa_code');
            $secretKey = $request->get('mfa_secret_key');

            // If invalid, redirect back with error
            if (!$mfaHandler->validateMFACode($mfaCode, $secretKey)) {
                // Generate a new secret key and QR image
                $secretAndQrImage = $mfaHandler->generateSecretAndQRImage($email);
                WRLAHelper::pushAlert('danger', 'Invalid MFA code, please try again');

                return redirect()->route($useRoute)->withInput()->with([
                    'mfa' => $mfaHandler->render2FAFormInitialSetup($email, $password, $secretAndQrImage['qrImage'], $secretAndQrImage['secretKey']),
                ]);
            }
            // If MFA code is valid, set the secret key and allow login process continue
            else {
                // Remove mfa_initial_setup from session
                $request->session()->forget('mfa_initial_setup');

                // Set secret key on wrla user data and save
                $wrlaUserData->setMFASecretKey($secretKey);
                $wrlaUserData->save();
                return null;
            }
        }
        /* User has a secret key set but no MFA code passed
        ---------------------------------------------------------------*/
        elseif(!empty($secretKey) && !$request->has('mfa_code')) {
            // Render MFA verify form
            return redirect()->route($useRoute)->with([
                'mfa' => $mfaHandler->render2FAValidationForm($email, $password),
            ]);
        }
        /* User has a secret key set and has passed an MFA code
        ---------------------------------------------------------------*/
        elseif(!empty($secretKey) && $request->has('mfa_code')) {
            // Get mfa code from request
            $mfaCode = $request->get('mfa_code');

            // If invalid, redirect back with error
            if (!$mfaHandler->validateMFACode($mfaCode, $secretKey)) {
                WRLAHelper::pushAlert('danger', 'Invalid MFA code, please try again');
                return redirect()->route($useRoute)->withInput()->with([
                    'mfa' => $mfaHandler->render2FAValidationForm($email, $password),
                ]);
            }
            // If MFA code is valid, allow login process continue
            else {
                // Remove mfa_initial_setup from session (just in case it exists somehow)
                $request->session()->forget('mfa_initial_setup');

                return null;
            }
        }

        // Invalid request, redirect back with error
    WRLAHelper::pushAlert('danger', 'Invalid MFA request, something went wrong');
    return redirect()->route($useRoute)->withInput();
    }

    /**
     * Generate secret key
     * @return array ['secretKey' => string, 'qrImage' => string]
     */
    public function generateSecretAndQRImage(string $userEmail): array {
        $result = match($this->mode) {

            // pragmarx/google2fa
            'pragmarx/google2fa' => function() use ($userEmail) {
                $google2fa = new Google2FA();

                $secretKey = $google2fa->generateSecretKey(32);

                return [
                    'secretKey' => $secretKey,
                    'qrImage' => $google2fa->getQRCodeInline(
                        WRLAHelper::insertConfigStrings($this->currentConfiguration['title']),
                        $userEmail,
                        $secretKey
                    )
                ];
            },

            default => false,
        };

        if ($result === false) {
            throw new \Exception("Unsupported MFA mode: {$this->mode}");
        }   

        return $result();
    }

    /**
     * Render 2FA form initial setup
     */
    public function render2FAFormInitialSetup(string $email, string $password, string $qrImage, string $mfaSecret): string {
        // Trim the QR image to remove any extra whitespace
        $qrImage = trim($qrImage);

        // If $qrImage is a base64 encoded image, we need to convert it to an <img> tag
        if (str_starts_with($qrImage, 'data:image/png;base64')) {
            $qrImage = '<img src="' . $qrImage . '" alt="QR Code" style="width: 200px; height: 200px;" />';
        }

        return Blade::render(<<<BLADE
            <div style="display: flex; flex-direction: column; justify-content: center; align-items: center; row-gap: 10px; width: 100%; margin-bottom: 10px; color: #888888; text-align: center; padding: 0px 20px;">
                <div>
                    Please scan the QR code below using your prefered authenticator app.
                </div>
                <div>
                    {!! \$qrImage !!}
                </div>
                <div>
                    And then enter the one time password below.
                </div>
                <input type="text" name="mfa_code" placeholder="Enter 2FA code" required autofocus style="width: 200px; padding: 5px 10px; border: 1px solid #AAAAAA; border-radius: 8px; text-align: center; font-size: 18px; font-weight: 600; letter-spacing: 2px;" />
                <input type="hidden" name="email" value="{{ \$email }}" />
                <input type="password" name="password" value="{{ \$password }}" class="hidden" />
                <input type="hidden" name="mfa_secret_key" value="{{ \$mfaSecret }}" />
            </div>
        BLADE, [
            'email' => $email,
            'password' => $password,
            'qrImage' => $qrImage,
            'mfaSecret' => $mfaSecret,
        ]);
    }

    /**
     * Render 2FA form validation
     */
    public function render2FAValidationForm(string $email, string $password): string {
        return Blade::render(<<<BLADE
            <div style="display: flex; flex-direction: column; justify-content: center; align-items: center; row-gap: 20px; width: 100%; margin-bottom: 10px; color: #888888; text-align: center; padding: 0px 20px;">
                <div style="">
                    Please enter the one time password below from your prefered authenticator app.
                </div>
                <input type="text" name="mfa_code" placeholder="Enter 2FA code" required autofocus style="width: 200px; padding: 5px 10px; border: 1px solid #CCCCCC; border-radius: 8px; text-align: center; font-size: 18px; font-weight: 600; letter-spacing: 2px;" />
                <input type="hidden" name="email" value="{{ \$email }}" />
                <input type="hidden" name="password" value="{{ \$password }}" />
            </div>
        BLADE, [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * Validate MFA code
     */
    public function validateMFACode(string $mfaCode, string $secretKey): bool {
        $result = match($this->mode) {

            // pragmarx/google2fa
            'pragmarx/google2fa' => function() use ($mfaCode, $secretKey) {
                $google2fa = new Google2FA();
                return $google2fa->verifyKey($secretKey, $mfaCode);
            },

            default => false,
        };

        if ($result === false) {
            throw new \Exception("Unsupported MFA mode: {$this->mode}");
        }

        return $result();
    }
}