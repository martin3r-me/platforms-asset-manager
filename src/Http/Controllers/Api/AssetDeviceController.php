<?php

namespace Platform\AssetManager\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetDevice;

class AssetDeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AssetDevice::class);

        $team = $request->user()->currentTeam;
        abort_unless($team, 403, 'Kein Team zugeordnet.');

        $devices = AssetDevice::where('team_id', $team->id)
            ->orderBy('device_name')
            ->paginate(50);

        return response()->json($devices);
    }

    public function show(Request $request, AssetDevice $device): JsonResponse
    {
        Gate::authorize('view', $device);

        return response()->json($device->makeHidden('raw_data'));
    }
}
