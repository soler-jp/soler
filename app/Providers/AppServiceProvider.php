<?php

namespace App\Providers;

use App\Services\CreditCardImport\AeonCreditCardCsvParser;
use App\Services\CreditCardImport\CreditCardCsvParserRegistry;
use App\Services\CreditCardImport\OricoCreditCardCsvParser;
use App\Services\CreditCardImport\RakutenCreditCardCsvParser;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CreditCardCsvParserRegistry::class, function () {
            return new CreditCardCsvParserRegistry([
                $this->app->make(OricoCreditCardCsvParser::class),
                $this->app->make(AeonCreditCardCsvParser::class),
                $this->app->make(RakutenCreditCardCsvParser::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
