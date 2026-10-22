<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\PermissionResource;
use App\Modules\Identity\Models\Permission;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    /** The permission catalogue. `individual_only` permissions can never be attached to a role. */
    public function index(): AnonymousResourceCollection
    {
        return PermissionResource::collection(Permission::orderBy('slug')->get());
    }
}
