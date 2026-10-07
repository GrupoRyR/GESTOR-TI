<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::inertia('/dashboard', 'Dashboard')->name('dashboard');
