<?php

namespace MonstreX\VoyagerExtension\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use League\Flysystem\Util;
use TCG\Voyager\Http\Controllers\VoyagerBaseController;
use TCG\Voyager\Facades\Voyager;
use Spatie\MediaLibrary\Models\Media;
use Illuminate\Support\Facades\Cache;


class VoyagerExtensionController extends BaseController
{


    /*
     * Load Translations
     */
    public function load_translations(Request $request) {
        return response()->json(Cache::get('translations'));
    }


    /*
     * Load AJAX Content (HTML rendered) using Request params
     */
    public function load_image_form(Request $request)
    {
        $slug = $request->get('slug');
        $field = $request->get('field');
        $id = $request->get('id');
        $media_file_id = $request->get('media_file_id');

        // Load related BREAD Data
        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();
        $dataRow = $dataType->editRows->filter(function($item) use ($field) {
            return $item->field == $field;
        })->first();

        // Load Related Media
        $model = app($dataType->model_name);
        $data = $model->findOrFail($id);
        $file = $data->getMedia($field)->where('id', $media_file_id)->first();

        return view('voyager-extension::forms.form-ajax', [
            'dataRow'      => $dataRow,
            'data'         => $data,
            'file'        => $file,
            'model'        => [
                'model'     => $dataType->model_name,
                'id'       => $id,
                'field'    => $field,
                'media_file_id' => $media_file_id,
            ]
        ]);
    }


    /*
     *  Update media file
     */
    public function update_media(Request $request)
    {
        $model_class = $request->get('model');
        $id = $request->get('id');
        $field = $request->get('field');
        $media_file_id = $request->get('media_file_id');

        try {
            // Load the related Record associated with a medialibrary file
            $model = app($model_class);
            $data = $model->find($id);
            $file = $data->getMedia($field)->where('id', $media_file_id)->first();

            $customFields = $request->except(['model', 'id', 'field', 'media_file_id']);
            foreach ($customFields as $key => $field) {
                $file->setCustomProperty($key, $field);
            }
            $file->save();

        } catch (Exception $error) {

            return json_response_with_error(500, $error);
        }

        return json_response_with_success(200, __('voyager-extension::bread.images_updated'));
    }






    /*
     *  Change media file
     */
    public function change_media(Request $request)
    {
        $model_class = $request->get('model');
        $slug = $request->get('slug');
        $id = $request->get('id');
        $field = $request->get('field');
        $media_file_id = $request->get('media_file_id');

        try {

            // Load the related Record associated with a medialibrary file
            $model = app($model_class);
            $data = $model->find($id);

            // Load related BREAD Data
            $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();
            $dataRow = $dataType->editRows->filter(function($item) use ($field) {
                return $item->field == $field;
            })->first();

            // Save OLD Properties
            $old_file = $data->getMedia($field)->where('id', $media_file_id)->first();
            $old_properties = [];
            $old_properties['title'] = $old_file->getCustomProperty('title');
            $old_properties['alt'] = $old_file->getCustomProperty('alt');

            if (isset($dataRow->details->extra_fields)) {
                foreach ($dataRow->details->extra_fields as $key => $item) {
                    $old_properties[$key] = $old_file->getCustomProperty($key);
                }
            }

            // Add New Image from Request
            $new_file = $data->addMediaFromRequest($field)
                ->withCustomProperties($old_properties)
                ->toMediaCollection($field);

            $all_files = $data->getMedia($field);
            $new_order = [];
            foreach ($all_files as $key => $item) {
                if ($item->id === (int) $media_file_id) {
                    $new_order[] = $new_file->id;
                } else {
                    $new_order[] = $item->id;
                }
            }

            Media::setNewOrder($new_order);

            $old_file->delete();

            $file_name_size = Str::limit($new_file->file_name, 20, ' (...)');
            $file_name_size .= ' <i class="' . ($new_file->size > 100000? 'large' : '') . '">' . $new_file->human_readable_size . '</i>';

            //\Debugbar::info($all_files);

        } catch (Exception $error) {

            return json_response_with_error(500, $error);
        }

        return json_response_with_success(
            200,
            __('voyager-extension::bread.images_updated'), [
            'file_url' => $new_file->getFullUrl(),
            'file_name' => $new_file->file_name,
            'file_name_size' => $file_name_size,
            'file_id' => $new_file->id,
        ]);
    }



    /*
     *  Remove media file
     */
    public function remove_media(Request $request)
    {
        $media_ids = $request->get('media_ids');

        \Debugbar::info($request);

        try {

            Media::destroy($media_ids);

        } catch (Exception $error) {
            return json_response_with_error(500, $error);
        }

        return json_response_with_success(200, __('voyager-extension::bread.images_removed'));
    }



    /*
     * Sort Media files
     */
    public function sort_media(Request $request)
    {
        \Debugbar::info($request);

        $files_ids_order = $request->get('files_ids_order');
        try {
            Media::setNewOrder($files_ids_order);
            return json_response_with_success(200, __('voyager-extension::bread.images_sorted'));
        } catch (Exception $error) {
            return json_response_with_error(500, $error);
        }
    }

    /*
     * Return content of the requested asset file (js, css and etc)
     *
     * This function used as is from the original Voyager 1.3.1
     */
    public function assets(Request $request)
    {

        try {
            $path = dirname(__DIR__, 3).'/voyager-extension/publishable/assets/'.Util::normalizeRelativePath(urldecode($request->path));
        } catch (\LogicException $e) {
            abort(404);
        }

        if (File::exists($path)) {
            $mime = '';
            if (Str::endsWith($path, '.js')) {
                $mime = 'text/javascript';
            } elseif (Str::endsWith($path, '.css')) {
                $mime = 'text/css';
            } else {
                $mime = File::mimeType($path);
            }
            $response = response(File::get($path), 200, ['Content-Type' => $mime]);
            $response->setSharedMaxAge(31536000);
            $response->setMaxAge(31536000);
            $response->setExpires(new \DateTime('+1 year'));

            return $response;
        }

        return response('', 404);
    }

}