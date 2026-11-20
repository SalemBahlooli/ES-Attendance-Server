@extends('errors.layout')
@section('code', '403')
@section('icon', 'fa-lock')
@section('title', __("You can't open this page"))
@section('message', isset($exception) && $exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.'
    ? $exception->getMessage()
    : __('Your account does not have the permission for it. Ask an administrator if you need access.'))
