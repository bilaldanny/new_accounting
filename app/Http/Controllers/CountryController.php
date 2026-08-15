<?php

namespace App\Http\Controllers;

use App\Models\Country;

class CountryController extends Controller
{
    public function fetch()
    {
        $countries = Country::select('name as text', 'countries.*')
            ->orderBy('created_at', 'ASC')
            ->get();

        return response()->json($countries);
    }
}
