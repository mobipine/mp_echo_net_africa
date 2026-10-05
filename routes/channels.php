<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('reports.{userId}', function ($user, int $userId): bool {
    return (int) $user->id === $userId;
});
