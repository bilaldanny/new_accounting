<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContactDuplicateController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizeMenuPermission('/contacts/duplicates');

        return response()->json(['data' => Contact::findDuplicateGroups()]);
    }

    public function merge(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/contacts/duplicates/merge');

        $request->validate([
            'keep_id' => 'bail|required|integer|exists:contacts,id',
            'duplicate_id' => 'bail|required|integer|exists:contacts,id',
        ]);

        DB::beginTransaction();
        try {
            $contact = Contact::mergeInto((int) $request->keep_id, (int) $request->duplicate_id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Merged', 'data' => $contact]);
    }
}
