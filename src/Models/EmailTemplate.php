<?php

namespace WebRegulate\LaravelAdministration\Models;

use Illuminate\Mail\Markdown;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

/**
 * Model: WrlaEmailTemplate
 * 
 * @property int $id bigint unsigned
 * @property string $category varchar(255)
 * @property string $alias varchar(255)
 * @property string $type varchar(255)
 * @property string $subject varchar(255)
 * @property string $body text
 * @property string $mappings json
 * @property string $description text
 * @property int $requires_attachment tinyint(1)
 * @property string $created_at timestamp
 * @property string $updated_at timestamp
 * @property string $deleted_at timestamp
 */
class EmailTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'wrla_email_templates';

    public const RENDER_MODE_BLADE = 'blade';

    public const RENDER_MODE_EMAIL = 'email';

    protected $guarded = [];

    public array $usingSMTPConfig = [];

    public ?array $dataArray = null;

    public bool $errorFound = false;

    public string $errorMessage = '';

    public null|string|array $replyTo = null;

    private static $postSendHookGlobal = null;

    private $postSendHook = null;

    /**
     * Post send hook global, note this gets called after the local post send hook of the email template.
     * Hook callable should take the following parameters:
     * - EmailTemplate $emailTemplate
     * - array $toAddresses
     * - array $attachments
     * - string $smtpKey
     */
    public static function setPostSendHookGlobal(callable $hook): void
    {
        self::$postSendHookGlobal = $hook;
    }

    /**
     * Make an line email template with subject and body only.
     */
    public static function make(string $subject, string $body, array $mappings = []): static
    {
        $emailTemplate = new static();
        $emailTemplate->setSubject($subject);
        $emailTemplate->setBody($body);

        if(!empty($mappings)) {
            $emailTemplate->setDataArray($mappings);
        }
        
        return $emailTemplate;
    }

    /**
     * Post send hook, note this gets called before the global post send hook.
     * Hook callable should take the following parameters:
     * - EmailTemplate $emailTemplate
     * - array $toAddresses
     * - array $attachments
     * - string $smtpKey
     */
    public function setPostSendHook(callable $hook): static
    {
        $this->postSendHook = $hook;
        return $this;
    }

    /**
     * Call before sendEmail() to set the reply-to address.
     */
    public function replyTo(string|array $replyTo): static
    {
        $this->replyTo = $replyTo;
        return $this;
    }

    /**
     * Get by alias.
     */
    public static function getByAlias(string $alias, array $dataOrModels = []): ?static
    {
        // Cache the email template for this request
        $emailTemplate = once(fn () => static::where('alias', 'like', $alias)->first());

        if ($emailTemplate == null) {
            throw new \Exception('Email template not found with alias: '.$alias);
        }

        // Set data array
        if (! empty($dataOrModels)) {
            $emailTemplate->setDataArray($dataOrModels);
        }

        return $emailTemplate;
    }

    /**
     * Get by caetgory and alias.
     */
    public static function getByCategoryAlias(string $category, string $alias, array $dataOrModels = []): ?static
    {
        // Cache the email template for this request
        $emailTemplate = once(fn () => static::where('category', $category)->where('alias', 'like', $alias)->first());

        if ($emailTemplate == null) {
            throw new \Exception("Email template not found with category: $category and alias: $alias");
        }

        // Set data array
        if (! empty($dataOrModels)) {
            $emailTemplate->setDataArray($dataOrModels);
        }

        return $emailTemplate;
    }

    /**
     * Set data array.
     *
     * @param  array  $dataArray  Can be key => data, key => model,... etc
     */
    public function setDataArray(array $dataArray): static
    {
        $this->dataArray = $this->buildDataArrayFromDataOrModels($dataArray);

        return $this;
    }

    /**
     * Merge data array
     *
     * @param  array  $dataArray  Can be key => data, key => model,... etc
     */
    public function mergeDataArray(array $dataArray): static
    {
        $this->dataArray = array_merge($this->dataArray ?? [], $this->buildDataArrayFromDataOrModels($dataArray));

        return $this;
    }

    /**
     * Get data element by dotted key.
     */
    public function getData(string $key): mixed
    {
        return data_get($this->dataArray, $key, null);
    }

    /**
     * Fill missing data in data array with (key.subkey here) placeholders.
     */
    public function fillMissingDataWithPlaceholders(): static
    {
        $data = $this->getKeyMappings();

        // dd($data);

        // Loop through key mappings and set each value based on model attributes
        foreach ($data as $key => $model) {
            try {
                if (! isset($this->dataArray[$key])) {
                    $this->dataArray[$key] = [];
                }

                foreach ($model as $modelKey => $modelValue) {
                    if (! isset($this->dataArray[$key][$modelKey])) {
                        $this->dataArray[$key][$modelKey] = "($key.$modelKey here)";
                    }
                }
            } catch (\Exception) {
                // dd($e->getMessage(), $key, $model);
            }
        }

        return $this;
    }

    /**
     * Get available key mappings.
     */
    public function getKeyMappings(): array
    {
        return once(function () {
            $mappings = ! empty($this->mappings)
                ? json_decode($this->mappings, true)
                : [];

            return $mappings ?? [];
        });
    }

    /**
     * Build data array for email template from models (but only the attributes available in key mappings).
     *
     * @param  array  $modelsOrData  eg. ['data' => ['key' => 'value',... ], 'user' => User::find(1), ...]
     */
    public function buildDataArrayFromDataOrModels(array $modelsOrData): array
    {
        // For some reason the below caching style was returning the dataArray from the previous emailTemplate... not sure why
        // if ($this->dataArray) {
        //     return $this->dataArray;
        // }

        $data = [];

        // Apply data/models to data if key mappings exist
        foreach ($modelsOrData as $key => $dataOrModel) {
            $keyMappings = $this->getKeyMappings();

            // If key does not exist in key mappings, skip
            // if(!isset($keyMappings[$key])) {
            //     continue;
            // }

            // If data is Model, get only the attributes that are in the key mappings
            if ($dataOrModel instanceof Model) {
                $data[$key] = $dataOrModel->only(array_keys($keyMappings[$key]));

                continue;
            }

            // If data is an array, string, or numeric, use that
            elseif (is_array($dataOrModel) || is_string($dataOrModel) || is_numeric($dataOrModel)) {
                $data[$key] = $dataOrModel;

                continue;
            }

            // Otherwise, do nothing
        }

        // Apply custom values to data array
        $data = $this->applyCustomisedDataValues($data);

        return $data;
    }

    /**
     * Apply customised data values to the data array.
     *
     * "field": null -> Uses whatever is passed from the modelsOrData array
     * "field": "some string" -> A fall back value if the modelsOrData array is empty()
     * More to come...
     */
    public function applyCustomisedDataValues(array $data): array
    {
        if (empty($data)) {
            return $data;
        }

        // Get key mappings
        $keyMappings = $this->getKeyMappings();

        // Loop through each data array and apply based on key mapping type (explained above)
        foreach ($keyMappings as $key => $mapping) {
            // If data is an array, loop through each key and value
            if (is_array($mapping)) {
                foreach ($mapping as $mappingKey => $mappingValue) {
                    // If mapping value is null, skip
                    if (is_null($mappingValue)) {
                        continue;
                    }
                    // If data value is empty and mapping value is a string, set it to the data value
                    elseif (is_string($mappingValue)) {
                        if (empty($data[$key][$mappingKey])) {
                            $data[$key][$mappingKey] = $mappingValue;
                        }
                    }
                }
            }
            // Else if $mapping is a string, set it to the data value
            elseif (is_string($mapping)) {
                // If data value is empty, set to mapping
                if (empty($data[$key])) {
                    $data[$key] = $mapping;
                }
            }
        }

        return $data;
    }

    /**
     * Set user data from email if exists. Note this also updates the SMTP config.
     */
    public function setUserDataFromEmail(string $email): static
    {
        $user = WRLAHelper::getUserModelClass()::where('email', $email)->first();

        if ($user !== null) {
            $this->dataArray = ['user' => $user->only(array_keys($this->getKeyMappings()['user']))];
        }

        return $this;
    }

    /**
     * Inject template variables (set in email key mappings config) into string. Eg. Hello {{ user.name }}.
     */
    public function injectVariablesIntoString(string $string, string $renderMode = self::RENDER_MODE_EMAIL): string
    {
        $buildString = $string;

        if ($this->errorFound) {
            return str($buildString)->replace('{', '(')->replace('}', ')')->toString();
        }

        try {
            // Loop through each data array and inject into string
            foreach ($this->dataArray as $key => $data) {
                // If data is an array, loop through each key and value
                if (is_array($data)) {
                    foreach ($data as $dataKey => $dataValue) {
                        $buildString = str_replace('{{ '.$key.'.'.$dataKey.' }}', $dataValue ?? '', $buildString);
                    }
                }
                // If data is string, just replace it
                elseif (is_string($data)) {
                    $buildString = str_replace('{{ '.$key.' }}', $data ?? '', $buildString);
                }
            }

            // Render mode escaping. HTML templates are authored as raw HTML and must
            // render verbatim (including inline <style> blocks and tags such as <p>),
            // so we skip the entity-escaping applied to markdown/text templates.
            if ($this->getRenderMode() === 'html') {
                // No escaping - output the HTML as written.
            } elseif ($renderMode == self::RENDER_MODE_BLADE) {
                $buildString = str_replace(['{', '}'], ['(', ')'], $buildString);
                $buildString = str_replace('@', "{{ '@' }}", $buildString);
                $buildString = str_replace('$', "{{ '$' }}", $buildString);
                $buildString = htmlspecialchars($buildString);
            } else {
                $buildString = htmlspecialchars($buildString);
                $buildString = str_replace(['{', '}'], ['&#123;', '&#125;'], $buildString);
                $buildString = str_replace('$', '&#36;', $buildString);
            }

            // Only convert newlines to <br> for plain-text output. Markdown and HTML
            // control their own line/paragraph breaks, and running nl2br first would
            // break block-level markdown parsing (headings, lists, etc).
            if ($this->getRenderMode() === 'text') {
                $buildString = nl2br($buildString);
            }
        } catch (\Exception $e) {
            $this->errorFound = true;
            $this->errorMessage = $e->getMessage();
        }

        return $buildString;
    }

    /**
     * Set subject template.
     */
    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Set body template.
     */
    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Resolve the render mode used to build the email ('markdown', 'html' or 'text').
     *
     * Prefers the per-template `type` column when set, otherwise falls back to the
     * global config so inline templates (via make()) keep their previous behaviour.
     */
    public function getRenderMode(): string
    {
        return $this->type ?: config('wr-laravel-administration.email_templates.render_mode', 'markdown');
    }

    /**
     * Get final subject
     */
    public function getFinalSubject(string $renderMode = self::RENDER_MODE_EMAIL): string
    {
        $subject = $this->injectVariablesIntoString($this->subject ?? '', $renderMode);

        // Fix for ' character
        return str($subject)->replace('&#39;', "'")->toString();
    }

    /**
     * Get final body
     */
    public function getFinalBody(string $renderMode = self::RENDER_MODE_EMAIL): string
    {
        return $this->injectVariablesIntoString($this->body ?? '', $renderMode);
    }

    /**
     * Render the body to HTML honouring the template's render mode. Markdown bodies
     * are converted (allowing inline HTML, and treating single newlines as line
     * breaks); HTML bodies are returned as-is.
     */
    public function getRenderedBody(string $renderMode = self::RENDER_MODE_EMAIL): string
    {
        $body = $this->getFinalBody($renderMode);

        if ($this->getRenderMode() === 'html') {
            return $body;
        }

        return (string) Str::markdown($body, [
            'html_input' => 'allow',
            'allow_unsafe_links' => true,
            'renderer' => [
                'soft_break' => "<br />\n",
            ],
        ]);
    }

    /**
     * Get value by dotted key from data array.
     */
    public function getValueByKey(string $key): mixed
    {
        return data_get($this->dataArray, $key);
    }

    /**
     * Send email to addresses with this template.
     *
     * @return bool $success
     */
    public function sendEmail(string|array $toAddresses, ?array $attachments = null, bool $sendSeperateEmails = true, string $smtpKey = 'smtp'): bool
    {
        if ($this->errorFound) {
            return false;
        }

        // Get current smtp and host from config
        $smtpData = config("mail.mailers.$smtpKey");
        $smtpHost = $smtpData['host'];

        // If smtpData does not have from.address or from.name, set them to the default values
        if (! isset($smtpData['from']['address']) || ! isset($smtpData['from']['name'])) {
            $smtpData['from']['address'] = config('mail.from.address');
            $smtpData['from']['name'] = config('mail.from.name');
        }

        // Normalise to array
        if (!is_array($toAddresses)) {
            $toAddresses = [$toAddresses];
        }

        // Flatten any comma-delimited entries (e.g. "a@x.com,b@y.com") and trim each address
        $flattened = [];
        foreach ($toAddresses as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            foreach (explode(',', $entry) as $address) {
                $address = trim($address);
                if ($address !== '') {
                    $flattened[] = $address;
                }
            }
        }
        $toAddresses = $flattened;

        // Remove any empty values or values without @ symbol
        $toAddresses = array_filter($toAddresses, fn ($toAddress) => ! empty($toAddress) && str($toAddress)->contains('@'));

        // If no to addresses, log and return false
        if (empty($toAddresses)) {
            Log::channel('smtp')->error('Email failed to send, no to addresses provided', [
                'email_template' => $this->alias,
                'smtp_key' => $smtpKey,
            ]);
            return false;
        }

        // Any failures check
        $failures = [];

        // If sendSeperateEmails is false, we will send the email to all addresses in one go
        if (!$sendSeperateEmails) {
            Log::channel('smtp')->info("Sending {$this->alias} email template ({$smtpHost}) to multiple addresses", [
                'mappings' => $this->dataArray,
                'to' => $toAddresses
            ]);

            // Build and send email (pass all to-addresses)
            $mail = $this->buildAndSendMail($smtpKey, $smtpData, $toAddresses, $attachments, $this->replyTo);

            // If not null, log it
            if ($mail === null) {
                Log::channel('smtp')->error('Email failed to send', ['to' => $toAddresses]);
                return false;
            }

            return true;
        }
        // Otherwise, we will loop through each address and send the email separately
        else {
            // Loop through to address and build / send email
            foreach ($toAddresses as $toAddress) {
                Log::channel('smtp')->info("Sending {$this->alias} email template ({$smtpHost})", [
                    'mappings' => $this->dataArray,
                    'to' => $toAddress
                ]);
    
                // Build and send email (pass only the current to-address to prevent duplicate sends)
                $mail = $this->buildAndSendMail($smtpKey ?? 'smtp', $smtpData, [$toAddress], $attachments, $this->replyTo);
    
                // If not null, log it
                if ($mail === null) {
                    $failures[] = $toAddress;
                    Log::channel('smtp')->error('Email failed to send', ['to' => $toAddress]);
                    continue;
                }
            }
        }

        // Return success if no failures found
        return empty($failures);
    }

    /**
     * Build and send mail
     */
    private function buildAndSendMail(string $smtpKey, array $smtpData, array $toAddresses, ?array $attachments, null|string|array $replyTo): ?SentMessage
    {
        // We send do the final here and check for success, this must be true before we execute the post send hooks
        $sentMessage = Mail::mailer($smtpKey)->send([], [], function($mail) use($smtpKey, $smtpData, $toAddresses, $attachments, $replyTo) {
            // Main
            $mail->from($smtpData['from']['address'], $smtpData['from']['name']);
            $mail->subject($this->getFinalSubject());
            $mail->to($toAddresses[0]);

            // Body
            match($this->getRenderMode()) {
                'markdown' => $mail->html($this->renderEmail(self::RENDER_MODE_EMAIL)),
                'html' => $mail->html($this->renderEmail(self::RENDER_MODE_EMAIL)),
                default => $mail->text($this->getFinalBody(self::RENDER_MODE_EMAIL)),
            };

            // Get the rest as cc addresses if there are any, also remove any empty values or values without @ symbol from these
            $ccAddresses = array_slice($toAddresses, 1);
            $ccAddresses = array_filter($ccAddresses, fn($ccAddress) => !empty($ccAddress) && str($ccAddress)->contains('@'));
            if(!empty($ccAddresses)) {
                $mail->cc($ccAddresses);
            }

            // Attachments
            if(!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    $mail->attach($attachment);
                }
            }
            
            // If replyTo is set, add it
            if($replyTo !== null) {
                $mail->replyTo($replyTo);
            }
        });

        // If sent message failed, return it now
        if ($sentMessage === null) {
            return null;
        }

        // Check post send hook, and post send hook global
        if (is_callable($this->postSendHook)) {
            call_user_func($this->postSendHook, $this, $toAddresses, $attachments, $smtpKey);
        }

        // If global post send hook is set, call it
        if (is_callable(self::$postSendHookGlobal)) {
            call_user_func(self::$postSendHookGlobal, $this, $toAddresses, $attachments, $smtpKey);
        }

        // Return sent message object
        return $sentMessage;
    }

    /**
     * Render email, returning the HTML.
     */
    public function renderEmail(string $renderMode = EmailTemplate::RENDER_MODE_EMAIL)
    {
        // Markdown emails go through the mail Markdown renderer so the <style> block is
        // inlined onto elements (email clients such as Gmail strip <head> styles).
        return match($this->getRenderMode()) {
            'markdown' => app(Markdown::class)->render('email.wrla.email-template-mail', [
                'emailTemplate' => $this,
                'renderMode' => $renderMode,
            ])->toHtml(),

            'html' => view('email.wrla.email-template-mail', [
                'emailTemplate' => $this,
                'renderMode' => $renderMode,
            ])->render(),

            default => $this->getFinalBody($renderMode),
        };
    }

    /**
     * Get available mappings list as formatted HTML
     */
    public function getMappingsListFormattedHTML(): string
    {
        $mappings = $this->getKeyMappings();
        
        $html = '<ul class="list-disc list-inside">';
        foreach ($mappings as $key => $mapping) {
            if (is_array($mapping)) {
                $html .= "<li>$key: &nbsp;";
                $html .= implode(', ', array_map(fn ($item) => <<<HTML
                        <span onclick="
                            window.wrlaInsertTextAtCursor('@{{ $key.$item }}');
                        " class="select-none cursor-pointer text-primary-700 font-medium">$key.$item</span>
                    HTML, array_keys($mapping)));
                $html .= '</li>';
            } else {
                $html .= <<<HTML
                    <li>$key: &nbsp;
                        <span onclick="
                            window.wrlaInsertTextAtCursor('@{{ $key }}');
                        " class="select-none cursor-pointer text-primary-700 font-medium">$key</span>
                    </li>
                HTML;
            }
        }
        $html .= '</ul>';

        return $html;
    }
}
