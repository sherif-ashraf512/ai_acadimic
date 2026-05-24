<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use Illuminate\Http\Request;

/**
 * Handles REGULAR material registration requests (type = regular).
 *
 * Routes prefix: /api/admin/regular-requests
 */
class AdminRegularRequestController extends Controller
{
    // ══════════════════════════════════════════════════════
    //  List
    // ══════════════════════════════════════════════════════

    /**
     * GET /admin/regular-requests
     * Query: ?status=pending|approved|rejected  ?student_id=X  ?per_page=10
     */
    public function index(Request $request)
    {
        $request->validate([
            'status'     => 'nullable|in:pending,approved,rejected',
            'student_id' => 'nullable|integer|exists:users,id',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        $per_page = $request->per_page ?? 10;

        $query = MaterialRequest::with(['student:id,name,code,level', 'course:id,name,code,credit_hours'])
            ->where('type', 'regular')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        $requests = $query->paginate($per_page);

        return $this->paginated($requests, 'requests');
    }

    // ══════════════════════════════════════════════════════
    //  Approve
    // ══════════════════════════════════════════════════════

    /**
     * POST /admin/regular-requests/approve
     * Body: { "ids": [1, 2, 3] }
     */
    public function approve(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:material_requests,id',
        ]);

        $updated = MaterialRequest::whereIn('id', $request->ids)
            ->where('type', 'regular')
            ->where('status', 'pending')
            ->update([
                'status'        => 'approved',
                'adviser_notes' => null,
            ]);

        return $this->success(
            ['updated_count' => $updated],
            "Approved {$updated} regular request(s) successfully."
        );
    }

    // ══════════════════════════════════════════════════════
    //  Reject
    // ══════════════════════════════════════════════════════

    /**
     * POST /admin/regular-requests/reject
     * Body: { "ids": [1, 2, 3], "adviser_notes": "Reason here." }
     */
    public function reject(Request $request)
    {
        $request->validate([
            'ids'           => 'required|array|min:1',
            'ids.*'         => 'required|integer|exists:material_requests,id',
            'adviser_notes' => 'required|string|max:1000',
        ]);

        $updated = MaterialRequest::whereIn('id', $request->ids)
            ->where('type', 'regular')
            ->where('status', 'pending')
            ->update([
                'status'        => 'rejected',
                'adviser_notes' => $request->adviser_notes,
            ]);

        return $this->success(
            ['updated_count' => $updated],
            "Rejected {$updated} regular request(s) successfully."
        );
    }
}
