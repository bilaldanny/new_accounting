<?php

namespace App\Http\Controllers;

use App\Models\State;
use Illuminate\Http\Request;

class StateController extends Controller
{
    public function fetch(Request $request)
    {

        $states = State::where('country_id', $request->country_id)
            ->select('name as text', 'states.*')
            ->orderBy('created_at', 'ASC')
            ->get();

        return response()->json($states);
    }
}
