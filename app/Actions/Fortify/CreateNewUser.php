<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    /** Throwaway-inbox services that bots sign up with (a Russian scam bot used fextemp.com, Oct 18 2026). */
    public const DISPOSABLE_DOMAINS = [
        'fextemp.com', 'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', 'grr.la', 'yopmail.com', '10minutemail.com',
        'temp-mail.org', 'tempmail.com', 'tempmail.net', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com',
        'mintemail.com', 'mohmal.com', 'emailondeck.com', 'fakeinbox.com', 'spamgourmet.com', 'tempinbox.com', 'mailnesia.com', 'mytemp.email',
    ];

    public function create(array $input): User
    {
        // Bot check (Oct 27 2026): a hidden field people never fill, a minimum time on the form, no links in names,
        // and no throwaway inboxes. Bots get one plain message; nothing says which check stopped them.
        $started = null;
        try {
            $started = (int) decrypt((string) ($input['form_started'] ?? ''));
        } catch (\Throwable) {
        }
        $tooFast = ! $started || now()->timestamp - $started < 3;
        if (trim((string) ($input['website'] ?? '')) !== '' || $tooFast) {
            \Illuminate\Support\Facades\Log::info('Sign-up blocked by the bot check', ['ip' => request()->ip(), 'email' => $input['email'] ?? null, 'honeypot' => ($input['website'] ?? '') !== '', 'too_fast' => $tooFast]);
            throw ValidationException::withMessages(['signup' => 'Sorry, we couldn\'t create your account. Please wait a moment and try again.']);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/(<|>|https?:|www\.|:\/\/|\.(ru|xyz|top)\b)/i'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
                function ($attr, $value, $fail) {
                    if (in_array(strtolower(substr(strrchr((string) $value, '@') ?: '', 1)), self::DISPOSABLE_DOMAINS, true)) {
                        $fail('Please use an email address you keep; temporary inboxes can\'t be used.');
                    }
                },
            ],
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
        ], ['terms.accepted' => 'Please agree to the Terms of Use and Privacy Policy.', 'name.not_regex' => 'Please enter just your name.'])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => strtolower($input['email']),
            'password' => Hash::make($input['password']),
        ]);
        \App\Support\Audit::record('user.registered', $user, null, ['email' => $user->email], null, $user->id);

        return $user;
    }
}
