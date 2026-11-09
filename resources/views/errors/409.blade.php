@include('errors.layout', ['code' => 409, 'title' => __('Record locked'), 'message' => $exception->getMessage() ?: __('This record can no longer be changed.')])
