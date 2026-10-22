<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\ApproveDevice;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Bootstrap and break-glass path: approve a device from the server shell. */
class ApproveDeviceCommand extends Command
{
    protected $signature = 'staff:approve-device
        {device : Device id printed at registration}
        {username : Staff username who owns this device}
        {--fingerprint= : Fingerprint shown on the employee\'s screen (required)}';

    protected $description = 'Approve a pending staff device and make it the property of one staff user';

    public function handle(ApproveDevice $approve): int
    {
        if (!Str::isUuid($this->argument('device'))) {
            $this->error('The device argument must be a device id (UUID).');

            return self::FAILURE;
        }

        if (!$this->option('fingerprint')) {
            $this->error('--fingerprint is required: read it from the device, do not copy it from the database.');

            return self::FAILURE;
        }

        $device = StaffDevice::find($this->argument('device'));

        if ($device === null) {
            $this->error('No such device.');

            return self::FAILURE;
        }

        $owner = StaffUser::where('username', mb_strtolower(trim($this->argument('username'))))->first();

        if ($owner === null) {
            $this->error('Unknown username.');

            return self::FAILURE;
        }

        try {
            $approve->execute($device, null, $this->option('fingerprint'), $owner);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info("Device {$device->id} approved for: " . $owner->username);

        return self::SUCCESS;
    }
}
