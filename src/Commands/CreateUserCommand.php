<?php

namespace WebRegulate\LaravelAdministration\Commands;

use Illuminate\Console\Command;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Models\WRLAUserDataBase;
use Faker;

class CreateUserCommand extends Command
{
    /**
     * The user that was created by this command, available after handle() completes.
     */
    public mixed $createdUser = null;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // Signature can optionally have master flag
    protected $signature = 'wrla:create-user {master?} {--user-model=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a WRLA user';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Resolve user model class (use passed option or fall back to config default)
        $userModel = $this->option('user-model')
            ? ltrim($this->option('user-model'), '\\')
            : ltrim(WRLAHelper::getUserModelClass(), '\\');

        // Check if master flag passed
        $master = $this->argument('master') !== null;

        // Generate a random name and email address
        $faker = Faker\Factory::create();
        $defaults = [
            'name' => $faker->name,
            'email' => $faker->email,
            'password' => $faker->password(8, 20),
        ];

        // If master isn't passed, check if the user wants to create a master user
        if (! $master) {
            $master = $this->confirm('Should we create a master user?', false);
        }

        // If not master, then ask if the user should be an admin
        if (! $master) {
            $admin = $this->confirm('Should the user be an admin?', false);
        } else {
            $admin = true;
        }

        // User text
        $userText = str($master ? 'Master user' : 'User');

        // Ask for name
        $name = $this->ask('Enter the name for the '.$userText->lower(), $defaults['name']);

        $emailSuccess = false;
        while ($emailSuccess === false) {
            // Ask for the email
            $email = $this->ask('Enter the email for the user', $defaults['email']);

            // Check if user already exists
            $user = $userModel::where('email', $email)->first();

            if ($user == null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emailSuccess = true;
            } else {
                $this->error('Invalid email address or email already exists. Please try again.');
            }
        }

        // Generate a random password for the default, and ask user to set
        $defaultPassword = $defaults['password'];
        $password = $this->ask('Enter the password for the '.$userText->lower(), $defaultPassword);

        // Create a standard user
        $userDataClass = WRLAHelper::getUserDataModelClass();
        $user = new $userModel;
        $user->name = $name;
        $user->email = $email;
        $user->{$userDataClass::getPasswordField()} = $userDataClass::hashPassword($password);
        $user->save();

        // Create WRLA user data record
        $wrlaUserData = new $userDataClass;
        if ($wrlaUserData instanceof WRLAUserDataBase) {
            $wrlaUserData->attachUser($user);
        } else {
            $wrlaUserData->user_id = WRLAHelper::buildUserId($user);
        }
        $wrlaUserData->permissions = json_encode([
            'master' => $master,
            'admin' => $admin,

        ]);
        $wrlaUserData->settings = json_encode([]);
        $wrlaUserData->data = json_encode([]);
        $wrlaUserData->save();

        $this->createdUser = $user;

        // Success message, display email and password on seperate lines, the text should be white, but the email and password should be in green
        $this->line('');
        $this->line($userText.' (<fg=yellow>'.$user->name.'</>) created successfully. Here are the login details:');
        $this->line('Email: `<fg=green>'.$user->email.'`</>');
        $this->line('Password: `<fg=green>'.$password.'</>`');

        // A yellow message to remind the user to login and change the email/password
        $this->line('');
        $loginPath = route('wrla.login');
        $this->line('<fg=yellow>Login URL: `</>'.$loginPath.'<fg=yellow>`</>');

        // New line for separation
        $this->line('');

        return 1;
    }
}
