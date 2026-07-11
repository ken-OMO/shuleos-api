<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\SchemeLessonResource;
use App\Models\AcademicWeek;
use App\Models\SchemeLesson;
use App\Services\Teaching\SchemeLessonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SchemeLessonController extends BaseCrudController
{
    private const MODULE = 'Scheme Lessons';
    private const RELATIONS = ['scheme', 'week', 'lessonPlans'];
    public function __construct(private readonly SchemeLessonService $service) {}
    public function index(Request $request)
    {
        $v=$request->validate(['scheme_id'=>'sometimes|uuid','week_id'=>'sometimes|uuid','per_page'=>'sometimes|integer|min:1|max:100']);
        $q=SchemeLesson::with(self::RELATIONS)->current()->whereHas('scheme',fn($x)=>$x->where('school_id',$this->schoolId($request))->where('is_deleted',false));
        foreach(['scheme_id','week_id'] as $f) $q->when(isset($v[$f]),fn($x)=>$x->where($f,$v[$f]));
        return $this->success(SchemeLessonResource::collection($q->orderBy('lesson_number')->paginate($v['per_page']??20)),'Scheme lessons retrieved successfully.');
    }
    public function show(Request $request,string $id)
    {
        $lesson=$this->tenantQuery($request)->with(self::RELATIONS)->find($id);
        return $lesson?$this->success(new SchemeLessonResource($lesson),'Scheme lesson retrieved successfully.'):$this->notFound('Scheme lesson not found.');
    }
    public function store(Request $request)
    {
        $v=$request->validate(['school_id'=>'sometimes|uuid|exists:schools,id','scheme_id'=>'required|uuid|exists:schemes_of_work,id','week_id'=>'required|uuid|exists:academic_weeks,id','lesson_number'=>'required|integer|min:1|max:1000','strand'=>'required|string|max:255','sub_strand'=>'required|string|max:255','specific_learning_outcome'=>'required|string','learning_experience'=>'required|string','resources'=>'nullable|string','assessment_method'=>'nullable|string']);
        $lesson=DB::transaction(function()use($request,$v){$lesson=$this->service->create($v,$this->schoolId($request,$v));$this->audit($request,self::MODULE,'Create',$lesson,null,$lesson->toArray(),'Created scheme lesson.');return $lesson;});
        return $this->created(new SchemeLessonResource($lesson->load(self::RELATIONS)),'Scheme lesson created successfully.');
    }
    public function update(Request $request,string $id)
    {
        $lesson=$this->tenantQuery($request)->find($id); if(!$lesson)return $this->notFound('Scheme lesson not found.');
        $v=$request->validate(['week_id'=>'sometimes|uuid|exists:academic_weeks,id','lesson_number'=>'sometimes|integer|min:1|max:1000','strand'=>'sometimes|string|max:255','sub_strand'=>'sometimes|string|max:255','specific_learning_outcome'=>'sometimes|string','learning_experience'=>'sometimes|string','resources'=>'nullable|string','assessment_method'=>'nullable|string']);
        if(isset($v['lesson_number'])&&SchemeLesson::where('scheme_id',$lesson->scheme_id)->where('lesson_number',$v['lesson_number'])->whereKeyNot($id)->exists())return $this->validation(['lesson_number'=>['This lesson number already exists in the scheme.']]);
        if(isset($v['week_id'])&&!AcademicWeek::whereKey($v['week_id'])->where('school_id',$lesson->scheme->school_id)->where('academic_year_id',$lesson->scheme->academic_year_id)->where('term_id',$lesson->scheme->term_id)->where('active',true)->exists())return $this->validation(['week_id'=>['The week does not belong to the scheme academic period.']]);
        $old=$lesson->toArray(); DB::transaction(function()use($request,$lesson,$v,$old){$lesson->update($v);$this->audit($request,self::MODULE,'Update',$lesson,$old,$lesson->fresh()->toArray(),'Updated scheme lesson.');});
        return $this->success(new SchemeLessonResource($lesson->refresh()->load(self::RELATIONS)),'Scheme lesson updated successfully.');
    }
    public function destroy(Request $request,string $id)
    {
        $lesson=$this->tenantQuery($request)->find($id); if(!$lesson)return $this->notFound('Scheme lesson not found.');
        DB::transaction(function()use($request,$lesson){$old=$lesson->toArray();$lesson->update(['is_deleted'=>true,'deleted_at'=>now(),'deleted_by'=>auth()->id()]);$this->audit($request,self::MODULE,'Delete',$lesson,$old,$lesson->toArray(),'Soft deleted scheme lesson.');});
        return $this->success(null,'Scheme lesson deleted successfully.');
    }
    private function tenantQuery(Request $r){return SchemeLesson::current()->whereHas('scheme',fn($q)=>$q->where('school_id',$this->schoolId($r))->where('is_deleted',false));}
    private function schoolId(Request $r,array $v=[]):string{$id=$r->attributes->get('tenant_school_id')??$v['school_id']??$r->input('school_id');abort_if(!$id,403,'School context not found.');return(string)$id;}
}
