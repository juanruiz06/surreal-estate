<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// As it is a Volt component, we register it as follows:
Volt::route('/', 'property-list');
