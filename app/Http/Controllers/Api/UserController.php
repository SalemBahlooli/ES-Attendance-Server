<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** GET /api/v1/users?q=&page= and GET /api/v1/users/{id} (permission users.view). */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::with('groups')
            ->search($request->query('q'))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('groups'));
    }
}
