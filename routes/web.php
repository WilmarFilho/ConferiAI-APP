<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Em routes/web.php
Route::get('/test-path', function () {
    $path = storage_path('app/chaves.json'); // <-- Troque pelo nome do seu arquivo
    
    // dd() significa "dump and die". Ele vai imprimir a variável e parar a execução.
    dd($path); 
});

require __DIR__.'/auth.php';
