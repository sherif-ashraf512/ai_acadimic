<?php

namespace App\Http\Controllers;

use App\Models\MaterialRequest;
use Illuminate\Http\Request;

class AdminMaterialRequestController extends Controller
{
    // ══════════════════════════════════════════════════════
    //  Admin: List All Requests (with filters)
    // ══════════════════════════════════════════════════════

    /**
     * GET /admin/material-requests
     * Query params: ?status=pending|approved|rejected  ?type=regular|graduation
     *               ?student_id=X  ?per_page=10
     */
    public function index(Request $request)
    {
        $request->validate([
            'status'     => 'nullable|in:pending,approved,rejected',
            'type'       => 'nullable|in:regular,graduation',
            'student_id' => 'nullable|integer|exists:users,id',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        $per_page = $request->per_page ?? 10;

        $query = MaterialRequest::with(['student', 'course'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        $requests = $query->paginate($per_page);

        return $this->paginated($requests, 'requests');
    }

    // ══════════════════════════════════════════════════════
    //  Admin: List Regular Requests only
    // ══════════════════════════════════════════════════════

    /** GET /admin/regular-requests */
    public function regularRequests(Request $request)
    {
        $request->merge(['type' => 'regular']);
        return $this->index($request);
    }

    // ══════════════════════════════════════════════════════
    //  Admin: List Graduation Requests only
    // ══════════════════════════════════════════════════════

    /** GET /admin/graduation-requests */
    public function graduationRequests(Request $request)
    {
        $request->merge(['type' => 'graduation']);
        return $this->index($request);
    }

    // ══════════════════════════════════════════════════════
    //  Admin: Approve Request(s)
    // ══════════════════════════════════════════════════════

    /**
     * POST /admin/material-requests/approve
     * Body: { "ids": [1, 2, 3] }
     */
    public function approve(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:material_requests,id',
        ]);

        $updated = MaterialRequest::whereIn('id', $request->ids)
            ->where('status', 'pending')
            ->update([
                'status'        => 'approved',
                'adviser_notes' => null,
            ]);

        return $this->success(
            ['updated_count' => $updated],
            "Approved {$updated} request(s) successfully."
        );
    }

    // ══════════════════════════════════════════════════════
    //  Admin: Reject Request(s)
    // ══════════════════════════════════════════════════════

    /**
     * POST /admin/material-requests/reject
     * Body: { "ids": [1, 2, 3], "adviser_notes": "Missing prerequisites." }
     */
    public function reject(Request $request)
    {
        $request->validate([
            'ids'           => 'required|array|min:1',
            'ids.*'         => 'required|integer|exists:material_requests,id',
            'adviser_notes' => 'required|string|max:1000',
        ]);

        $updated = MaterialRequest::whereIn('id', $request->ids)
            ->where('status', 'pending')
            ->update([
                'status'        => 'rejected',
                'adviser_notes' => $request->adviser_notes,
            ]);

        return $this->success(
            ['updated_count' => $updated],
            "Rejected {$updated} request(s) successfully."
        );
    }
}
