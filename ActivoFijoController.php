<?php

namespace backend\modules\contabilidad\controllers;

use backend\controllers\BaseController;
use backend\models\Empresa;
use backend\models\SessionVariables;
use backend\modules\contabilidad\models\ActivoFijo;
use backend\modules\contabilidad\models\ActivoFijoAtributo;
use backend\modules\contabilidad\models\ActivoFijoStockManager;
use backend\modules\contabilidad\models\ActivoFijoTipo;
use backend\modules\contabilidad\models\ActivoFijoTipoAtributo;
use backend\modules\contabilidad\models\Asiento;
use backend\modules\contabilidad\models\AsientoDetalle;
use backend\modules\contabilidad\models\CoeficienteRevaluo;
use backend\modules\contabilidad\models\EmpresaPeriodoContable;
use backend\modules\contabilidad\models\FormRevaluoAFijo;
use backend\modules\contabilidad\models\ParametroSistema;
use backend\modules\contabilidad\models\PlantillaCompraventa;
use backend\modules\contabilidad\models\PlantillaCompraventaDetalle;
use backend\modules\contabilidad\models\search\ActivoFijoSearch;
use backend\modules\contabilidad\models\Venta;
use backend\modules\contabilidad\models\VentaIvaCuentaUsada;
use common\helpers\FlashMessageHelpsers;
use kartik\form\ActiveForm;
use PHPExcel_Shared_Date;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Yii;
use yii\base\Exception;
use yii\base\Model;
use yii\db\Transaction;
use yii\filters\VerbFilter;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * ActivoFijoController implements the CRUD actions for ActivoFijo model.
 */
class ActivoFijoController extends BaseController
{

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    private function clearSession()
    {
        $sesion = Yii::$app->session;

        $sesion->remove('cont_afijo_atributos');
        $sesion->remove('costo_adq_desde_factura');
        $sesion->remove('total-costo-adq');
    }

    /**
     * Lists all ActivoFijo models.
     * @return mixed
     */
    public function actionIndex()
    {
        self::clearSession();

        $searchModel = new ActivoFijoSearch();
        $searchModel->empresa_id = Yii::$app->session->get(SessionVariables::empresa_actual);
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single ActivoFijo model.
     * @param string $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        $model = $this->findModel($id);
        $icus = VentaIvaCuentaUsada::find()->where(['factura_venta_id' => $model->factura_venta_id])->all();
        $totalIcu = 0;
        $ganancia = 0;
        /** @var VentaIvaCuentaUsada $icu */
        foreach ($icus as $icu) {
            if ($icu->ivaCta->iva->porcentaje == 10 || $icu->ivaCta->iva->porcentaje == 5) {
                $totalIcu += round($icu->monto / (1.00 + ($icu->ivaCta->iva->porcentaje / 100.0)), 2);
            } else {
                $totalIcu += $icu->monto;
            }
        }
        if ($totalIcu > 0)
            $ganancia = $totalIcu - $model->valor_contable_revaluado;

        return $this->render('view', [
            'model' => $model,
            'ganancia' => $ganancia
        ]);
    }

    public function actionValidateValor()
    {
        $valor = $_GET['valor'];
        $tipo_id = $_GET['tipo_id'];
        $atributo = $_GET['atributo'];
        Yii::$app->response->format = Response::FORMAT_JSON;

        $query = ActivoFijoTipoAtributo::find()->where(['atributo' => $atributo, 'activo_fijo_tipo_id' => $tipo_id]);
        if (!$query->exists())
            return ['error' => ''];

        /** @var ActivoFijoTipoAtributo $_atributo */
        $_atributo = $query->one();
        if ($_atributo->obligatorio == 'si' && ($valor == '' | $valor == ' '))
            return ['error' => "El valor del atributo `{$atributo}` es obligatorio."];

        return ['error', ''];
    }

    /**
     * Creates a new ActivoFijo model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate($_pjax = null)
    {
        $model = new ActivoFijo();
        //        $model->loadForTesting();
        $model->empresa_id = Yii::$app->session->get(SessionVariables::empresa_actual);
        $model->empresa_periodo_contable_id = Yii::$app->session->get('core_empresa_actual_pc');
        $sesion = Yii::$app->session;

        if (Yii::$app->request->isGet && $_pjax == null) {
            self::clearSession();
        }

        if ($model->load(Yii::$app->request->post())) {
            $model->vida_util_restante = $model->vida_util_fiscal;
            $model->empresa_periodo_contable_id = Yii::$app->session->get('core_empresa_actual_pc');
            $model->cuenta_id = $model->activoFijoTipo->cuenta_id;
            $model->cuenta_depreciacion_id = $model->activoFijoTipo->cuenta_depreciacion_id;

            $trans = Yii::$app->db->beginTransaction();
            try {
                // Recibimos del POST los atributos y ponemos ya en la sesion.
                /** @var ActivoFijoAtributo[] $atributos */
                $atributos = [];
                if (array_key_exists('ActivoFijoAtributo', $_POST)) {
                    foreach ($_POST['ActivoFijoAtributo'] as $atributo) {
                        $_atributo = new ActivoFijoAtributo();
                        $_atributo->loadEmpresaPeriodo();
                        $load['ActivoFijoAtributo'] = $atributo;
                        $_atributo->load($load);
                        $_atributo->activo_fijo_tipo_id = $model->activo_fijo_tipo_id;
                        if (!isset($_atributo->valor) || $_atributo->valor == '')
                            $_atributo->valor = " ";
                        $atributos[] = $_atributo;
                    }
                }
                $sesion->set('cont_afijo_atributos', ['tipo_id' => $model->activo_fijo_tipo_id, 'atributos' => $atributos]);

                // Generamos id.
                if (!$model->save()) {
                    throw new \Exception("Error guardando activo fijo: {$model->getErrorSummaryAsString()}");
                }
                $model->observacion = 'creado desde abm';
                $model->refresh();

                // Procesamos atributos.
                foreach ($atributos as $index => $atributo) {
                    $atributo->activo_fijo_id = $model->id;
                    /** @var ActivoFijoTipoAtributo $atributoTipo */
                    $atributoTipo = ActivoFijoTipoAtributo::find()->where([
                        'activo_fijo_tipo_id' => $model->activo_fijo_tipo_id,
                        'atributo' => $atributo->atributo,
                    ])->one();

                    if (!$atributo->validate()) {
                        throw new \Exception("Error validando atributo: {$atributo->getErrorSummaryAsString()}");
                    }

                    if ($atributoTipo->obligatorio == 'si' && $atributo->valor == '') {
                        $atributo->id = null;
                        throw new \Exception("Atributo `{$atributo->atributo}` no puede ser
                         vacío.");
                    }

                    if (!$atributo->save(false)) {
                        $atributo->id = null;
                        throw new \Exception("Error guardando atributo `{$atributo->atributo}`:
                         {$atributo->getErrorSummaryAsString()}");
                    }
                }

                $trans->commit();
                FlashMessageHelpsers::createSuccessMessage('Creado exitosamente.');
                return $this->redirect(['index']);
            } catch (\Exception $exception) {
                //                throw $exception;
                $trans->rollBack();
                FlashMessageHelpsers::createWarningMessage($exception->getMessage());
                $model->id = null;
            }
        }

        return $this->render('create', ['model' => $model,]);
    }

    /**
     * Updates an existing ActivoFijo model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param string $id
     * @param null $_pjax
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     * @throws \Throwable
     */
    public function actionUpdate($id, $_pjax = null)
    {
        $model = $this->findModel($id);
        $sesion = Yii::$app->session;

        if (!$this->isEditable($model)) {
            return $this->redirect(['index']);
        }

        if (Yii::$app->request->isGet && $_pjax == null) {
            // Cargar moneda_id
            if (isset($model->compra)) {
                $model->moneda_id = $model->compra->moneda_id;
            }

            // Cargar atributos
            /** @var  $_atributos ActivoFijoTipoAtributo[] */
            $atributos = ActivoFijoAtributo::findAll(['activo_fijo_id' => $id]);
            $atribs_re = ActivoFijoAtributo::findAll(['activo_fijo_id' => $id]); // atributos a renderizar en la vista.
            $_atributos = ActivoFijoTipoAtributo::find()->where(['activo_fijo_tipo_id' => $model->activo_fijo_tipo_id])->all();

            // Agregar atributos nuevos provenientes del tipo, que no estuvieron al crear el activo fijo.
            foreach ($_atributos as $_atributo) {
                $hay = false;
                foreach ($atributos as $atributo) {
                    if ($atributo->atributo == $_atributo->atributo) {
                        $hay = true;
                        break;
                    }
                }
                if (!$hay) {
                    $_newAtributo = new ActivoFijoAtributo();
                    $_newAtributo->loadEmpresaPeriodo();
                    $_newAtributo->atributo = $_atributo->atributo;
                    $_newAtributo->valor = '';
                    $atribs_re[] = $_newAtributo;
                }
            }
            $sesion->set('cont_afijo_atributos', ['tipo_id' => $model->activo_fijo_tipo_id, 'atributos' => $atribs_re]);
            //FIN Cargar atributo
        }

        if ($model->load(Yii::$app->request->post())) {
            $transaction = Yii::$app->db->beginTransaction();

            try {
                $model->cuenta_id = $model->activoFijoTipo->cuenta_id;
                $model->cuenta_depreciacion_id = $model->activoFijoTipo->cuenta_depreciacion_id;

                /** @var ActivoFijoAtributo[] $atributos */
                $atributos = [];
                if (array_key_exists('ActivoFijoAtributo', $_POST)) {
                    foreach ($_POST['ActivoFijoAtributo'] as $atributo) {
                        $_atributo = new ActivoFijoAtributo();
                        $_atributo->loadEmpresaPeriodo();
                        $load['ActivoFijoAtributo'] = $atributo;
                        $_atributo->load($load);
                        $_atributo->activo_fijo_tipo_id = $model->activo_fijo_tipo_id;
                        if (!isset($_atributo->valor) || $_atributo->valor == '')
                            $_atributo->valor = " ";
                        $atributos[] = $_atributo;
                    }
                }
                $sesion->set('cont_afijo_atributos', ['tipo_id' => $model->activo_fijo_tipo_id, 'atributos' => $atributos]);

                if (!$model->save()) {
                    throw new \yii\base\Exception("Error validando formulario: {$model->getErrorSummaryAsString()}");
                }

                // Controlar que no haya modificado la moneda puesta por compra.
                if (isset($model->compra) && $model->moneda_id != $model->compra->moneda_id) {
                    $msg = "No puede modificar la moneda ya que este A. Fijo está asociado a la Factura de Compra {$model->compra->nro_factura}";
                    $model->addError('moneda_id', $msg);
                    throw new \yii\base\Exception($msg);
                }

                // Borrar atributos anteriores.
                foreach ($model->atributos as $atributo) {
                    if (!$atributo->delete()) {
                        throw new \Exception("Error borrando atributos anteriores:
                         {$atributo->getErrorSummaryAsString()}");
                    }
                }

                // Procesar atributos del POST.
                foreach ($atributos as $index => $atributo) {
                    $atributo->activo_fijo_id = $model->id;
                    /** @var ActivoFijoTipoAtributo $atributoTipo */
                    $atributoTipo = ActivoFijoTipoAtributo::find()->where([
                        'activo_fijo_tipo_id' => $model->activo_fijo_tipo_id,
                        'atributo' => $atributo->atributo,
                    ])->one();

                    if (!$atributo->validate()) {
                        throw new \Exception("Error validando atributo: {$atributo->getErrorSummaryAsString()}");
                    }

                    if ($atributoTipo->obligatorio == 'si' && $atributo->valor == '') {
                        $atributo->id = null;
                        throw new \Exception("Atributo `{$atributo->atributo}` no puede ser
                         vacío.");
                    }

                    if (!$atributo->save()) {
                        $atributo->id = null;
                        throw new \Exception("Error validando atributo `{$atributo->atributo}`:
                         {$atributo->getErrorSummaryAsString()}");
                    }
                }

                $transaction->commit();
                FlashMessageHelpsers::createSuccessMessage('Editado exitosamente.');
                return $this->redirect(['index']);
            } catch (\Exception $exception) {
                $transaction->rollBack();
                FlashMessageHelpsers::createWarningMessage($exception->getMessage());
                $model->id = null;
            }
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * @param $model ActivoFijo
     * @return bool
     */
    private function isEditable(&$model)
    {
        if (isset($model->compra) && isset($model->compra->asiento)) {
            FlashMessageHelpsers::createWarningMessage('La Factura de Compra Nº ' . $model->compra->nro_factura . ' de este Activo Fijo tiene asientos generados.');
            return false;
        }

        return true;
    }

    public function actionLoadAtributos()
    {
        $afijo_id = $_GET['afijo_id'];
        $afijo_tipo_id = $_GET['afijo_tipo_id'];

        $sesionData = Yii::$app->session->get('cont_afijo_atributos', []);
        if (!empty($sesionData)) {
            $results = $sesionData['atributos'];

            if ($afijo_tipo_id == $sesionData['tipo_id'] && !empty($results)) {
                return true;
            }
        }

        $results = [];
        $query = ActivoFijoAtributo::find()->where(['activo_fijo_id' => $afijo_id, 'activo_fijo_tipo_id' => $afijo_tipo_id]);

        /** @var ActivoFijoAtributo[] $atributos */
        /** @var ActivoFijoTipoAtributo[] $_atributos */
        if ($query->exists()) {
            $results = $query->all();
            $atributos = $query->all();
            $_atributos = ActivoFijoTipoAtributo::find()->where(['activo_fijo_tipo_id' => $afijo_tipo_id])->all();

            foreach ($_atributos as $_atributo) {
                $hay = false;
                foreach ($atributos as $atributo) {
                    if ($_atributo->atributo == $atributo->atributo) {
                        $hay = true;
                        break;
                    }
                }

                if (!$hay) {
                    $_newAtributo = new ActivoFijoAtributo();
                    $_newAtributo->loadEmpresaPeriodo();
                    $_newAtributo->atributo = $_atributo->atributo;
                    $_newAtributo->valor = '';
                    $results[] = $_newAtributo;
                }
            }
        } else {
            $atributos = ActivoFijoTipoAtributo::find()->where(['activo_fijo_tipo_id' => $afijo_tipo_id])->all();
            foreach ($atributos as $atributo) {
                $model_atributo = new ActivoFijoAtributo();
                $model_atributo->loadEmpresaPeriodo();
                $model_atributo->atributo = $atributo->atributo;
                $model_atributo->activo_fijo_tipo_id = $afijo_tipo_id;
                $results[] = $model_atributo;
            }
        }

        $sesionData = ['tipo_id' => $afijo_tipo_id, 'atributos' => $results];
        Yii::$app->session->set('cont_afijo_atributos', $sesionData);
        return true;
    }

    /**
     * @param $id
     * @return Response
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function actionDelete($id)
    {
        $model = $this->findModel($id);
        $transaccion = Yii::$app->db->beginTransaction();
        try {
            if (!isset($model))
                throw new NotFoundHttpException('The requested page doesn´t found');
            if (isset($model->asiento))
                throw new ForbiddenHttpException('Existen uno o más asientos generados por este Activo Fijo. Elimínelos primero.');
            if (isset($model->venta))
                throw new ForbiddenHttpException('Este Activo Fijo está asociada a la Factura de Venta Nº ' . $model->venta->getNroFacturaCompleto() . '. Elimínelo primero.');
            if (isset($model->compra))
                throw new ForbiddenHttpException('Este Activo Fijo está asociada a la Factura de Compra Nº ' . $model->compra->nro_factura . '. Elimínelo primero.');
            foreach ($model->atributos as $atributo) {
                if (!$atributo->delete())
                    throw new \Exception("Error eliminando atributos asociados: {$atributo->getErrorSummaryAsString()}");
            }

            $model->delete();
            $transaccion->commit();
            FlashMessageHelpsers::createSuccessMessage('El Activo Fijo ' . $model->nombre . ' se ha eliminado correctamente.');
        } catch (\Exception $exception) {
            $transaccion->rollBack();
            FlashMessageHelpsers::createWarningMessage('El Activo Fijo no se puede borrar: ' . $exception->getMessage() . '.');
        }


        return $this->redirect(['index']);
    }

    /**
     * Finds the ActivoFijo model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param string $id
     * @return ActivoFijo the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected
    function findModel($id)
    {
        if (($model = ActivoFijo::find()->where(["id"=>$id])->one()) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }

    /**
     * Metodo que debe ser implementado retornando la lista de operaciones que no necesitan empresa.
     *
     * En caso de que el controller no requiera de ningún control por empresa se debe retornar false.
     *
     * @return mixed
     */
    function getNoRequierenEmpresa()
    {
        return [];
    }

    /** ------------------------ [inicio] ACTIVO FIJO DESDE COMPRA ------------------------ */

    /**
     * @param $dataModel ActivoFijo
     * @return ActivoFijo
     */
    private function deepCopy($dataModel)
    {
        $model = new ActivoFijo();
        $model->loadDefaultValues();
        $model->id = $dataModel->id;
        $model->activo_fijo_tipo_id = $dataModel->activo_fijo_tipo_id;
        $model->nombre = $dataModel->nombre;
        $model->costo_adquisicion = $dataModel->costo_adquisicion;
        $model->fecha_adquisicion = $dataModel->fecha_adquisicion;
        $model->vida_util_fiscal = $dataModel->vida_util_fiscal;
        $model->vida_util_restante = $dataModel->vida_util_restante;
        $model->valor_fiscal_neto = $dataModel->valor_fiscal_neto;
        $model->cantidad = $dataModel->cantidad;

        return $model;
    }

    /**
     * @param $dataModels ActivoFijoAtributo[]
     * @return ActivoFijoAtributo[]
     */
    private function deepCopyAtributos($dataModels)
    {
        $models = [];
        foreach ($dataModels as $dataModel) {
            $model = new ActivoFijoAtributo();
            $model->loadEmpresaPeriodo();
            $model->id = $dataModel->id;
            $model->activo_fijo_tipo_id = $dataModel->activo_fijo_tipo_id;
            $model->activo_fijo_id = $dataModel->activo_fijo_id;
            $model->atributo = $dataModel->atributo;
            $model->valor = $dataModel->valor;
            $models[] = $model;
        }

        return $models;
    }

    public function actionCantidadDeferredValidator()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $activoFijoTipoId = Yii::$app->request->get('activo_fijo_tipo_id', null);

        if (!isset($activoFijoTipoId))
            return false;

        $tipo = ActivoFijoTipo::findOne($activoFijoTipoId);
        $cantidad = Yii::$app->request->get('cantidad', null);
        if (isset($tipo) && $tipo->cantidad_requerida == 'si' && (!isset($cantidad) || $cantidad == ''))
            return 'Cantidad no puede estar vacío.';

        return false;
    }

    /**
     *  Manejador de activos fijos para la vistas create y update de compras
     *  no elimina Activos Fijos de la base de datos, que a cargo del controller de compras
     */
    public function actionManejarDesdeFacturaCompra($formodal,$plantilla_id, $costo_adquisicion = "", $fecha_factura = null, $submit = false, $moneda_id = null,$btn=null,$activo_nro = null)
    {
        $plantilla = PlantillaCompraventa::findOne((int)$plantilla_id);
       $activosFijos = Yii::$app->session->get("activos_fijos_compra");
       $activoFijoAtributo = Yii::$app->session->get("activos_fijos_atributos_compra");

        if(!isset($activosFijos)){
            $activosFijos =[];
        }
        if(!isset($activosFijos[$plantilla_id])){
            $activosFijos[$plantilla_id] =[];
        }
        if(!isset($activoFijoAtributo)){
            $activoFijoAtributo =[];
        }
        if(!isset($activoFijoAtributo[$plantilla_id])){
            $activoFijoAtributo[$plantilla_id] =[];
        }
        $cantidadActivos = count($activosFijos[$plantilla_id]);
       // si no existen datos en la base de datos creamos uno vacio
       if($cantidadActivos == 0 and $btn==null and $submit == false){
        //    echo "primera fila";
            $activoNuevo = new ActivoFijo();
            $activoNuevo->loadDefaultValues();
            // $activoNuevo->nombre = "desde vacio";
            $activoNuevo->cantidad = 1;
            $activoNuevo->activo_fijo_tipo_id = $plantilla->activo_fijo_tipo_id;
            $activoNuevo->moneda_id = $moneda_id;
            $activoNuevo->fecha_adquisicion = $fecha_factura;
            $activoNuevo->costo_adquisicion = $costo_adquisicion;
            $activoNuevo->valor_fiscal_neto = $costo_adquisicion;
            $activoNuevo->vida_util_fiscal = $activoNuevo->vida_util_fiscal?? $activoNuevo->activoFijoTipo->vida_util;
            $activoNuevo->vida_util_contable = $activoNuevo->vida_util_contable?? $activoNuevo->activoFijoTipo->vida_util;
            $activoNuevo->vida_util_restante = $activoNuevo->vida_util_restante?? $activoNuevo->activoFijoTipo->vida_util;
            $activoNuevo->cuenta_id = PlantillaCompraventa::getFirstCuentaPrincipal($plantilla->id)->p_c_gravada_id;
            $activosFijos[$plantilla_id][0] = $activoNuevo;
            Yii::$app->session->set('activos_fijos_compra',$activosFijos);
            Yii::$app->session->set('activos_fijos_atributos_compra',$activoFijoAtributo);
            goto retorno;
       }
       //BOTON ELIMINAR
       if($btn=='eliminar' and $activo_nro != null and isset($activosFijos[$plantilla_id][$activo_nro])){
            if (isset($activoFijoAttributo[$plantilla_id][$activo_nro])) {
                    foreach($activoFijoAtributo[$plantilla_id][$activo_nro] as $nro_atributo => $_attributo){ 
                        $activoFijoAtributo[$plantilla_id][$activo_nro][$nro_atributo]->delete();
                        $activoFijoAtributo[$plantilla_id][$activo_nro][$nro_atributo] = null;
                        unset($activoFijoAtributo[$plantilla_id][$activo_nro][$nro_atributo]);
                    }
                    unset($activoFijoAtributo[$plantilla_id][$activo_nro]);
                }
                unset($activosFijos[$plantilla_id][$activo_nro]);
                Yii::$app->session->set('activos_fijos_compra',$activosFijos);
                Yii::$app->session->set('activos_fijos_atributos_compra',$activoFijoAtributo);
                goto retorno;
       }
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        $get = Yii::$app->request->get();
        $agrego = false;
        if (Yii::$app->request->isAjax && isset($get['ActivoFijo'])) {
            
            //BOTON GUARDAR
            foreach($get['ActivoFijo'] as $nro_activo => $activo){
                if(isset($activosFijos[$plantilla_id][$nro_activo])){
                    $activoFijoModel = $activosFijos[$plantilla_id][$nro_activo];
                    // $activoFijoModel->loadDefaultValues();
                    $activoFijoModel->setAttributes($activo);
                    $activoFijoModel->activo_fijo_tipo_id = $plantilla->activo_fijo_tipo_id;
                    $activoFijoModel->moneda_id = $moneda_id;
                    $activoFijoModel->fecha_adquisicion = $fecha_factura;
                    $activoFijoModel->costo_adquisicion = $costo_adquisicion;
                    $activoFijoModel->valor_fiscal_neto = $costo_adquisicion;
                    $activoFijoModel->vida_util_fiscal = $activoFijoModel->vida_util_fiscal?? $activoFijoModel->activoFijoTipo->vida_util;
                    $activoFijoModel->vida_util_contable = $activoFijoModel->vida_util_contable?? $activoFijoModel->activoFijoTipo->vida_util;
                    $activoFijoModel->vida_util_restante = $activoFijoModel->vida_util_restante?? $activoFijoModel->activoFijoTipo->vida_util;
                    $activoFijoModel->cuenta_id = PlantillaCompraventa::getFirstCuentaPrincipal($plantilla->id)->p_c_gravada_id;
                    $activoFijoModel->fecha_adquisicion = $fecha_factura;
                    $activosFijos[$plantilla_id][$nro_activo] = $activoFijoModel;
                }
            }
            foreach ($activosFijos[$plantilla_id] as $activoKey => $activoFijo){
                
                //implementamos nuestro loadMultiple debido al que el de yii no soporta arrays multidimensionales
                if (!isset($get['ActivoFijoAtributo'][$activoKey])){
                    continue;
                }
                foreach ($get['ActivoFijoAtributo'][$activoKey] as $atribKey => $_atributo){
                    $atributoModel = new ActivoFijoAtributo();
                    $atributoModel->activo_fijo_tipo_id = $activoFijo->activo_fijo_tipo_id;
                    $atributoModel->atributo = $_atributo['atributo'];
                    $atributoModel->valor = $_atributo['valor'];
                    $activoFijoAtributo[$plantilla_id][$activoKey][$atribKey] = $atributoModel;
                }
            }
            Yii::$app->session->remove('activos_fijos_compra');
            Yii::$app->session->remove('activos_fijos_atributos_compra');
            Yii::$app->session->set('activos_fijos_compra',$activosFijos);
            Yii::$app->session->set('activos_fijos_atributos_compra',$activoFijoAtributo);
            if($submit == true){
                return true;    
            }
           
            //BOTON + genera muchos mas
            
            if($submit == false && $btn == 'nuevo'){
                $activoNuevo = new ActivoFijo();
                $activoNuevo->loadDefaultValues();
                $activoNuevo->nombre = "";
                $activoNuevo->cantidad = 1;
                $activoNuevo->activo_fijo_tipo_id = $plantilla->activo_fijo_tipo_id;
                $activoNuevo->moneda_id = $moneda_id;
                // $activoNuevo->fecha_adquisicion = $fecha_factura;
                $activoNuevo->costo_adquisicion = $costo_adquisicion;
                $activoNuevo->valor_fiscal_neto = $costo_adquisicion;
                $activoNuevo->vida_util_fiscal = $activoNuevo->vida_util_fiscal?? $activoNuevo->activoFijoTipo->vida_util;
                $activoNuevo->vida_util_contable = $activoNuevo->vida_util_contable?? $activoNuevo->activoFijoTipo->vida_util;
                $activoNuevo->vida_util_restante = $activoNuevo->vida_util_restante?? $activoNuevo->activoFijoTipo->vida_util;
                $activoNuevo->cuenta_id = PlantillaCompraventa::getFirstCuentaPrincipal($plantilla->id)->p_c_gravada_id;
                $activosFijos[$plantilla_id][] = $activoNuevo;
                Yii::$app->session->remove('activos_fijos_compra');
                // Yii::$app->session->remove('activos_fijos_atributos_compra');
                Yii::$app->session->set('activos_fijos_compra',$activosFijos);
                // Yii::$app->session->set('activos_fijos_atributos_compra',$activoFijoAtributo);
                $agrego = true;
            }
            // return true;
        }
        //por alguna extraña razon agrega demas, con este codigo eliminamos los que sobran
        if($agrego = true){
            $eliminar = false;
            foreach($activosFijos[$plantilla_id] as $activoKey => $activoFijo){
                if($activoFijo->nombre == ""){
                    if($eliminar == true){
                        unset($activosFijos[$plantilla_id][$activo_nro]);
                        // echo "<pre>";var_dump($activosFijos);exit;
                    }
                    $eliminar = true;
                }
            }
            Yii::$app->session->remove('activos_fijos_compra');
            Yii::$app->session->set('activos_fijos_compra',$activosFijos);
            
        }
        retorno:;
        // echo "<pre>;</pre>var_dump($activosFijos);exit;
        return $this->renderAjax('create_compras', [
            'modelos' => $activosFijos[$plantilla_id],
            'activoFijoAtributo'=>$activoFijoAtributo[$plantilla_id],
            'plantilla_id'=>$plantilla_id,
        ]);
    }

    /** ------------------------ [FIN] ACTIVO FIJO DESDE COMPRA ------------------------ */




    public function actionManejarDesdeFacturaCompra_old($formodal,$plantilla_id, $costo_adquisicion = "", $fecha_factura = null, $submit = false, $actfijo_id = null, $quiere_crear_nuevo = 'no', $goto = "", $moneda_id = null, $tipo_id = null)
    {
        
        $model = new ActivoFijo();
        $model->loadDefaultValues();

        $plantilla = PlantillaCompraventa::findOne((int)$plantilla_id);
    //    echo '<pre>'; var_dump ($plantilla->activo_fijo_tipo_id);echo '</pre>'; exit;
       $model->activo_fijo_tipo_id = $plantilla->activo_fijo_tipo_id;
        if (isset($moneda_id)) {
            Yii::$app->session->set('cont_compra_actfijo_moneda_id', $moneda_id);
        }
        $tipo_id = $model->activo_fijo_tipo_id;
        echo $tipo_id; 
        if (isset($tipo_id)) {
            $tipo_id = $model->activo_fijo_tipo_id;
            Yii::$app->session->set('cont_compra_actfijo_tipo_id', $tipo_id);
        }

        // Como parte de flujo normal, se espera que la fecha (invisible si es modal) y el costo de adquisición (no editable)
        //  sea igual a lo que se haya configurado antes de reabrir el modal. Ej.: Si se cambió la fecha de emisión,
        //  cambiar fecha de adquisición, al reabrir el modal. Si se cambió el monto de algún campo de la fila correspondiente
        //  a la plantilla de Activo fijo, al reabrir el modal debe cambiar también el costo de adquisición.
        $model->fecha_adquisicion = $fecha_factura;
        $model->costo_adquisicion = $costo_adquisicion;
        $model->valor_fiscal_neto = $model->costo_adquisicion;

        // $model->activo_fijo_tipo_id = Yii::$app->session->get('cont_compra_actfijo_tipo_id');

        {
            // Cargar modelo con los datos actuales del formulario
            $model->nombre = Yii::$app->request->get('nombre', '');
            $model->vida_util_fiscal = Yii::$app->request->get('vida_u_fiscal', '');
            $model->vida_util_contable = Yii::$app->request->get('vida_u_contable', '');
            $model->cantidad = Yii::$app->request->get('cantidad', '');
        }
//        echo '<pre>'; var_dump ($model);echo '</pre>'; exit;

        $skey = "cont_actfijo_desde_factura";
        if (Yii::$app->session->has($skey)) {
            $dataProvider = Yii::$app->session->get($skey);
            $model = $dataProvider[0]['model'];
            // var_dump($dataProvider[0]['model']->id);
            self::cargarAtributo($model->id);
        }

        if ($goto == 'primera_vez') {
            Yii::$app->session->remove('cont_afijo_atributos');
            if($plantilla->activo_fijo_tipo_id != null){
                $model->activo_fijo_tipo_id = $plantilla->activo_fijo_tipo_id;
            }
            if ($costo_adquisicion != '')
                Yii::$app->session->set('costo_adq_desde_factura', $costo_adquisicion);
        } else if ($goto == 'forward') {
            $skey = "cont_actfijo_desde_factura";
            $skey2 = "cont_actfijo_pointer";
            if (Yii::$app->session->has($skey)) {
                $allModels = Yii::$app->session->get($skey);
                $pointer = (int)Yii::$app->session->get($skey2);
                $data = [];
                if ($pointer + 1 >= sizeof($allModels))
                    $pointer = sizeof($allModels) - 1;
                else
                    $pointer++;
                if (sizeof($allModels) > 0) {
                    $model = $this->deepCopy($allModels[(int)$pointer]['model']);
                    $atributos = $this->deepCopyAtributos($allModels[(int)$pointer]['atributos']);
                    $data = ['tipo_id' => $model->activo_fijo_tipo_id, 'atributos' => $atributos];
                } else {
                    $pointer = 0;
                    $model->activo_fijo_tipo_id = '';
                }
                Yii::$app->session->set('cont_afijo_atributos', $data);
                Yii::$app->session->set($skey2, $pointer);
            }
        } elseif ($goto == 'rewind') {
            $skey = "cont_actfijo_desde_factura";
            $skey2 = "cont_actfijo_pointer";
            if (Yii::$app->session->has($skey)) {
                $allModels = Yii::$app->session->get($skey);
                $pointer = (int)Yii::$app->session->get($skey2);
                $data = [];
                if ($pointer - 1 < 0)
                    $pointer = 0;
                else
                    $pointer--;
                if (sizeof($allModels) > 0) {
                    $model = $this->deepCopy($allModels[(int)$pointer]['model']);
                    $atributos = $this->deepCopyAtributos($allModels[(int)$pointer]['atributos']);
                    $data = ['tipo_id' => $model->activo_fijo_tipo_id, 'atributos' => $atributos];
                } else {
                    $pointer = 0;
                    $model->activo_fijo_tipo_id = '';
                }
                Yii::$app->session->set('cont_afijo_atributos', $data);
                Yii::$app->session->set($skey2, $pointer);
            }
        }

        $model->moneda_id = Yii::$app->session->get('cont_compra_actfijo_moneda_id');

        Yii::$app->response->format = Response::FORMAT_JSON;
        if (Yii::$app->request->isAjax && $model->load(Yii::$app->request->post()) && $submit == false) {
            $model->vida_util_restante = $model->vida_util_fiscal;
            $model->loadDefaultValues();
            return ActiveForm::validate($model);
        }

        // Este bloque esta debajo de la validacion, para que $model tenga cargado lo que ha venido del post.
        // Se tiene que verificar que el model apuntado por el puntero no sea el mismo que el del modal.
        // Puede haber clickeado unas veces <- o -> y haya hecho que en la sesion tenga puesto $pointer.
        // Pero a la vez puede que haya establecido un nuevo nombre, que todavia no haya guardado en la sesion cuyo nombre
        //   no coincidira con el del apuntado por el $pointer.
        if ($goto == 'delete') {
            $skey = "cont_actfijo_desde_factura";
            $skey2 = "cont_actfijo_pointer";
            $pointer = Yii::$app->session->get($skey2, null);
            $allModels = Yii::$app->session->get($skey, null);
            $nombre = Yii::$app->request->get('nombre', null);
            if ($nombre != '' && isset($pointer) && isset($allModels) && sizeof($allModels) > 0 && $nombre == $allModels[$pointer]['model']->nombre) {
                unset($allModels[$pointer]);
                $allModels = array_values($allModels);
            }
            $model->nombre = '';
            $model->cantidad = '';
            $model->activo_fijo_tipo_id = '';
            $model->valor_fiscal_neto = $model->costo_adquisicion;

            // Regresar al primer elemento.
            $pointer = 0;
            Yii::$app->session->remove('cont_afijo_atributos');
            Yii::$app->session->set($skey2, $pointer);
            Yii::$app->session->set($skey, $allModels);
        }

        // cuando cambia de tipo de activofijo
        if ($goto == 'render_atributos') {
            if ($model->activo_fijo_tipo_id == '')
                Yii::$app->session->remove('cont_afijo_atributos');
            else {
                if (!Yii::$app->session->has('cont_afijo_atributos')) { // no hay atributos en la sesion
                    $atributos = $model->atributos;
                    if (empty($atributos)) // el modelo es nuevo
                        if (isset($model->activoFijoTipo)) // el tipo de afijo esta definido
                            foreach ($model->activoFijoTipo->atributos as $atributo) {
                                $_atributo = new ActivoFijoAtributo();
                                $_atributo->loadEmpresaPeriodo();
                                $_atributo->atributo = $atributo->atributo;
                                $atributos[] = $_atributo;
                            }

                    $sesionData = ['tipo_id' => $model->activo_fijo_tipo_id, 'atributos' => $atributos];
                    Yii::$app->session->set('cont_afijo_atributos', $sesionData);
                } else {
                    $atributos = [];
                    $sesionData = Yii::$app->session->get('cont_afijo_atributos');
                    $sesion_afijo_tipo_id = $sesionData['tipo_id'];
                    if (isset($model->activoFijoTipo) && $sesion_afijo_tipo_id != $model->activo_fijo_tipo_id) {
                        foreach ($model->activoFijoTipo->atributos as $atributo) {
                            $_atributo = new ActivoFijoAtributo();
                            $_atributo->loadEmpresaPeriodo();
                            $_atributo->atributo = $atributo->atributo;
                            $atributos[] = $_atributo;
                        }
                    } else {
                        $atributos = $sesionData['atributos'];
                    }
                    $sesionData = ['tipo_id' => $model->activo_fijo_tipo_id, 'atributos' => $atributos];
                    Yii::$app->session->set('cont_afijo_atributos', $sesionData);
                }
            }
        }

        if ($model->load(Yii::$app->request->post()) && $submit != false) {
            $model->vida_util_restante = $model->vida_util_fiscal;
            $model->moneda_id = Yii::$app->session->get('cont_compra_actfijo_moneda_id');
            $model->cuenta_id = $model->activoFijoTipo->cuenta_id; // activo_fijo_tipo_id es requerido.
            $model->cuenta_depreciacion_id = $model->activoFijoTipo->cuenta_depreciacion_id;
            $model->cantidad = ($model->activoFijoTipo->cantidad_requerida == 'no' && $model->cantidad == null) ? 1 : $model->cantidad;
            /** @var PlantillaCompraventaDetalle $p_det */
            if ($model->validate()) {
                $pointer = null;
                $atributos = [];
                $atributosPost = Yii::$app->request->post('ActivoFijoAtributo', []);
                $aFijosCompra = Yii::$app->session->get('cont_actfijo_desde_factura', null);

                // Modelos para atributos del Afijo.
                foreach ($atributosPost as $atributo_post) {
                    $atributo = new ActivoFijoAtributo();
                    $atributo->loadEmpresaPeriodo();
                    $load['ActivoFijoAtributo'] = $atributo_post;
                    $atributo->load($load);
                    $atributo->activo_fijo_tipo_id = $model->activo_fijo_tipo_id;
                    if (!isset($atributo->valor) || $atributo->valor == '')
                        $atributo->valor = " ";
                    $atributos[] = $atributo;
                }

                // Guardar activos fijos en sesion, con sus atributos.
                if (!isset($aFijosCompra)) {
                    $aFijosCompra = [];
                    $aFijosCompra[] = [
                        'model' => $model,
                        'atributos' => $atributos,
                    ];
                } else {
                    /** @var ActivoFijo[] $allModels */
                    foreach ($aFijosCompra as $index => $item) {
                        if ($item['model']->nombre == $model->nombre)
                            unset($aFijosCompra[$index]);
                    }
                    // Tener en cuenta que el push() hace que incremente el index.
                    // Además, si se ejecutó unset(), puede que el primer (o el único) model no tenga index 0.
                    array_push($aFijosCompra, ['model' => $model, 'atributos' => $atributos]);
                    $aFijosCompra = array_values($aFijosCompra);
                }
                Yii::$app->session->set('cont_actfijo_desde_factura', $aFijosCompra);
                Yii::$app->session->set('cont_actfijo_pointer', $pointer);
                Yii::$app->session->remove('cont_afijo_atributos');
                Yii::$app->session->remove('costo_adq_desde_factura');
            } else {
                FlashMessageHelpsers::createErrorMessage('Error en la validación: ' . $model->getErrorSummary(true)[0]);
            }
        }

        retorno:;
        return $this->renderAjax('create', [
            'model' => $model,
        ]);
    }

    /** ------------------------ [FIN] ACTIVO FIJO DESDE COMPRA ------------------------ */

    /** ---------------------- [INICIO] ACTIVO FIJO DESDE VENTA ---------------------- */
    public function actionManejarDesdeFacturaVenta($formodal, $plantilla_id, $costo_adquisicion, $venta_id = null, $fecha_factura = null, $submit = false, $actfijo_id = null, $quiere_crear_nuevo = 'no')
    {
        $array_left = [];
        $array_right = [];
        $skey = 'cont_activofijo_ids';
        $activos_fijos = ActivoFijo::find()->where([
            'empresa_id' => Yii::$app->session->get('core_empresa_actual'),
            'empresa_periodo_contable_id' => Yii::$app->session->get('core_empresa_actual_pc'),
            'estado' => 'activo',
        ])/* ->andWhere([ // 18 Marzo 19: Se decide quitar para que puedan vender los activos fijos sin factura de compra, bajo concenso con Jose.
                          'IS NOT', 'factura_compra_id', null // no se permite vender activos fijos que no tengan facturas de compra.
                          ]) */
            ->andWhere([
                'IS', 'factura_venta_id', null // solo los que no fueron vendidos.
            ])->andWhere([
                'IS NOT', 'moneda_id', null // no se permite vender activos fijos que no tengan asociados moneda.
            ])->all();

        $ids_activo_fijo_session = [];
        if (Yii::$app->session->has($skey)) {
            $ids_activo_fijo_session = Yii::$app->session->get($skey);
        }
        $ids_activo_fijo_venta = [];
        if (empty($ids_activo_fijo_session))
            if (isset($venta_id) && $venta_id != "") {
                foreach (Venta::findOne($venta_id)->activoFijos as $activoFijo) {
                    $ids_activo_fijo_venta[] = $activoFijo->id;
                }
            }
        $ids_to_right = array_values(array_unique(array_merge($ids_activo_fijo_session, $ids_activo_fijo_venta)));

        foreach ($activos_fijos as $index => $activo_fijo) {
            foreach ($ids_to_right as $id) {
                if ($activo_fijo->id == $id) {
                    unset($activos_fijos[$index]);
                }
            }
        }
        foreach (array_values($activos_fijos) as $activo_fijo) {
            $array_left[] = ['id' => $activo_fijo->id, 'text' => $activo_fijo->id . ' - ' . $activo_fijo->nombre];
        }
        foreach ($ids_to_right as $id) {
            $activo_fijo = ActivoFijo::findOne($id);
            $array_right[] = ['id' => $activo_fijo->id, 'text' => $activo_fijo->id . ' - ' . $activo_fijo->nombre];
        }

        //        if (Yii::$app->session->has($skey)) {
        //            foreach ($activos_fijos as $activo_fijo) {
        //                $flag = false;
        //                foreach (\Yii::$app->session->get($skey) as $activo_fijo_id) {
        //                    if ($activo_fijo->id == $activo_fijo_id) {
        //                        $flag = true;
        //                        break;
        //                    }
        //                }
        //
        //                if (!$flag)
        //                    $array_left[] = ['id' => $activo_fijo->id, 'text' => $activo_fijo->id . ' - ' . $activo_fijo->nombre];
        //                else
        //                    $array_right[] = ['id' => $activo_fijo->id, 'text' => $activo_fijo->id . ' - ' . $activo_fijo->nombre];
        //            }
        //        } elseif (isset($venta_id) && $venta_id != "") {
        //            $venta = Venta::findOne(['id' => $venta_id]);
        //            $activos_fijos_venta = $venta->activoFijos;
        //
        //            if (!isset($activos_fijos_venta)) {
        //                $activos_fijos_venta = $activos_fijos;
        //            }
        //
        //            if (isset($venta->activoFijos)) {
        //                foreach ($activos_fijos as $activo_fijo) {
        //                    $flag = false;
        //                    foreach ($venta->activoFijos as $activo_fijo_venta) {
        //                        if ($activo_fijo->id == $activo_fijo_venta->id) {
        //                            $flag = true;
        //                            break;
        //                        }
        //                    }
        //                    if (!$flag)
        //                        $array_left[] = ['id' => $activo_fijo->id, 'text' => $activo_fijo->id . ' - ' . $activo_fijo->nombre];
        //                    else
        //                        $array_right[] = ['id' => $activo_fijo->id, 'text' => $activo_fijo->id . ' - ' . $activo_fijo->nombre];
        //                }
        //            } else {
        //                foreach ($activos_fijos_venta as $item) {
        //                    $array_left[] = ['id' => $item->id, 'text' => $item->id . ' - ' . $item->nombre];
        //                }
        //            }
        //        } else {
        //            foreach ($activos_fijos as $item) {
        //                $array_left[] = ['id' => $item->id, 'text' => $item->id . ' - ' . $item->nombre];
        //            }
        //        }
        $json_pick_left = json_encode($array_left);
        $json_pick_right = json_encode($array_right);

        Yii::$app->response->format = Response::FORMAT_JSON;
        if (Yii::$app->request->isPost) {
            $activofijo_ids = [];
            if (array_key_exists('selected', $_POST))
                $activofijo_ids = $_POST['selected'];
            if (sizeof($activofijo_ids) > 0)
                Yii::$app->session->set($skey, $activofijo_ids);
            else
                Yii::$app->session->remove($skey);
        }

        return $this->renderAjax('_form_select_from_venta', [
            'json_pick_left' => $json_pick_left,
            'json_pick_right' => $json_pick_right,
        ]);
    }

    /** ---------------------- [FIN] ACTIVO FIJO DESDE VENTA ---------------------- */

    /** ---------------------- [INI] ACTIVO FIJO DESDE NOTA CREDITO VENTA ---------------------- */
    public function actionManejarDesdeNotaCreditoVenta($prefijoFilaPlantilla = null, $venta_id = null, $ventaNotaCredito_id = null)
    {
        $array_left = [];
        $array_right = [];
        $skey = 'cont_activofijo_ids';
        $skey_prefijoFilaPlantilla = 'cont_prefijo_fila_plantilla';
        $skey_ventaId = 'cont_venta_id';
        $skey_ventaNCreditoId = 'cont_venta_nota_credito_id';

        // ids asociados a la factura de venta
        $ids_activo_fijo_venta = []; {
            if (isset($prefijoFilaPlantilla) && $prefijoFilaPlantilla != '')
                Yii::$app->session->set($skey_prefijoFilaPlantilla, $prefijoFilaPlantilla);
            if (isset($venta_id) && $venta_id != '')
                Yii::$app->session->set($skey_ventaId, $venta_id);
            if (isset($ventaNotaCredito_id) && $ventaNotaCredito_id != '')
                Yii::$app->session->set($skey_ventaNCreditoId, $ventaNotaCredito_id);
        }

        // Si cambio de factura, limpiar sesion
        $dataProvider = Yii::$app->session->get($skey, []);
        if (sizeof($dataProvider) && $dataProvider['venta_id'] != Yii::$app->session->get($skey_ventaId)) {
            Yii::$app->session->remove($skey);
        }

        if (!Yii::$app->session->has($skey)) {
            $ids = [];
            foreach (ActivoFijoStockManager::findAll([
                'factura_venta_id' => Yii::$app->session->get($skey_ventaId),
                'venta_nota_credito_id' => Yii::$app->session->get($skey_ventaNCreditoId)
            ]) as $manager) {

                $ids[] = $manager->activo_fijo_id;
                $ids_activo_fijo_compra[] = $manager->activo_fijo_id; // los devueltos inicialmente formaran parte de la izq.
            }
            $ids = [
                'venta_id' => Yii::$app->session->get($skey_ventaId),
                'ids' => $ids,
            ];
            Yii::$app->session->set('cont_activofijo_ids', $ids);
        }

        // Anhadir los que no fueron devueltos.
        foreach (ActivoFijo::findAll(['factura_venta_id' => Yii::$app->session->get($skey_ventaId)]) as $activoFijo) {
            $ids_activo_fijo_venta[] = $activoFijo->id;
        }

        // ids en la sesión, a ir a la derecha
        $ids_activo_fijo_session = [];
        if (Yii::$app->session->has($skey)) {
            $dataProvider = Yii::$app->session->get($skey);
            $ids_activo_fijo_session = $dataProvider['ids'];
        }

        // La idea es:
        // - A la izquierda, todos los ids de activos fijos de la compra que aun no fueron devueltos.
        // - A la derecha, todos los que serán desvinculados (o los que fueron desvinculados) de la compra.
        // Remover de la izquierda los seleccionados (los de la sessión).
        $innerLooped = false;
        foreach ($ids_activo_fijo_venta as $index => $id_actfijo_venta) {
            foreach ($ids_activo_fijo_session as $id_actfijo_session) {
                if (!$innerLooped)
                    $innerLooped = true;
                if ($id_actfijo_venta == $id_actfijo_session) {
                    unset($ids_activo_fijo_venta[$index]);
                }
            }
            if (!$innerLooped)
                break;
        }

        // Cargar izquierda - derecha.
        foreach (array_values($ids_activo_fijo_venta) as $id) {
            $af = ActivoFijo::findOne($id);
            $array_left[] = ['id' => $id, 'text' => $id . ' - ' . $af->nombre, 'costo_adq' => $af->costo_adquisicion];
        }
        foreach ($ids_activo_fijo_session as $id) {
            $af = ActivoFijo::findOne($id);
            $array_right[] = ['id' => $id, 'text' => $id . ' - ' . $af->nombre, 'costo_adq' => $af->costo_adquisicion];
        }

        $json_pick_left = json_encode($array_left);
        $json_pick_right = json_encode($array_right);

        if (Yii::$app->request->isPost) {
            $activofijo_ids = [];
            if (array_key_exists('selected', $_POST))
                $activofijo_ids = $_POST['selected'];
            if (sizeof($activofijo_ids) > 0) {
                $ids = [
                    'venta_id' => Yii::$app->session->get($skey_ventaId),
                    'ids' => $activofijo_ids,
                ];
                Yii::$app->session->set($skey, $ids);

                $total_costo_adq = 0;
                foreach ($ids['ids'] as $id) {
                    $total_costo_adq += (float)ActivoFijo::findOne(['id' => $id])->costo_adquisicion;
                }
                Yii::$app->session->set("total-costo-adq", ($total_costo_adq * 1.1));
            } else {
                Yii::$app->session->remove($skey);
            }
        }

        Yii::$app->response->format = Response::FORMAT_JSON;
        return $this->renderAjax('_form_select_from_venta', [
            'json_pick_left' => $json_pick_left,
            'json_pick_right' => $json_pick_right,
        ]);
    }

    /** Action temporal para settear la moneda_id de los activos fijos por los id de moneda de las facturas de compras
     * correspondiente.
     *
     * @return Response
     */
    public function actionAsociarMoneda()
    {
        /** @var ActivoFijo $activoFijo */
        try {
            foreach (ActivoFijo::find()->all() as $activoFijo) {
                if (isset($activoFijo->compra)) {
                    $activoFijo->moneda_id = $activoFijo->compra->moneda_id;
                    if (!$activoFijo->save()) {
                        throw new Exception($activoFijo->getErrorSummaryAsString());
                    }
                }
            }
            FlashMessageHelpsers::createSuccessMessage('Procedimiento ejecutado correctamente.');
        } catch (Exception $exception) {
            FlashMessageHelpsers::createWarningMessage($exception->getMessage());
        }
        return $this->redirect(['index']);
    }

    public function actionManejarDesdeNotaCreditoCompra($prefijoFilaPlantilla = null, $compra_id = null, $compraNotaCredito_id = null)
    {
        $array_left = [];
        $array_right = [];
        $skey = 'cont_activofijo_ids';
        $skey_prefijoFilaPlantilla = 'cont_prefijo_fila_plantilla';
        $skey_compraId = 'cont_compra_id';
        $skey_compraNCreditoId = 'cont_compra_nota_credito_id';

        // ids asociados a la factura de compra
        $ids_activo_fijo_compra = []; {
            if (isset($prefijoFilaPlantilla) && $prefijoFilaPlantilla != '')
                Yii::$app->session->set($skey_prefijoFilaPlantilla, $prefijoFilaPlantilla);
            if (isset($compra_id) && $compra_id != '')
                Yii::$app->session->set($skey_compraId, $compra_id);
            if (isset($compraNotaCredito_id) && $compraNotaCredito_id != '')
                Yii::$app->session->set($skey_compraNCreditoId, $compraNotaCredito_id);
        }

        // Si cambio de factura, limpiar sesion
        $dataProvider = Yii::$app->session->get($skey, []);
        if (sizeof($dataProvider) && $dataProvider['compra_id'] != Yii::$app->session->get($skey_compraId)) {
            Yii::$app->session->remove($skey);
        }

        if (!Yii::$app->session->has($skey)) {
            $ids = [];
            foreach (ActivoFijoStockManager::findAll([
                'factura_compra_id' => Yii::$app->session->get($skey_compraId),
                'compra_nota_credito_id' => Yii::$app->session->get($skey_compraNCreditoId)
            ]) as $manager) {

                $ids[] = $manager->activo_fijo_id;
                $ids_activo_fijo_compra[] = $manager->activo_fijo_id; // los devueltos inicialmente formaran parte de la izq.
            }
            $ids = [
                'compra_id' => Yii::$app->session->get($skey_compraId),
                'ids' => $ids,
            ];
            Yii::$app->session->set('cont_activofijo_ids', $ids);
        }

        // Anhadir los que no fueron devueltos.
        foreach (ActivoFijo::findAll(['factura_compra_id' => Yii::$app->session->get($skey_compraId)]) as $activoFijo) {
            $ids_activo_fijo_compra[] = $activoFijo->id;
        }

        // ids en la sesión, a ir a la derecha
        $ids_activo_fijo_session = [];
        if (Yii::$app->session->has($skey)) {
            $dataProvider = Yii::$app->session->get($skey);
            $ids_activo_fijo_session = $dataProvider['ids'];
        }

        // La idea es:
        // - A la izquierda, todos los ids de activos fijos de la compra que aun no fueron devueltos.
        // - A la derecha, todos los que serán desvinculados (o los que fueron desvinculados) de la compra.
        // Remover de la izquierda los seleccionados (los de la sessión).
        $innerLooped = false;
        foreach ($ids_activo_fijo_compra as $index => $id_actfijo_compra) {
            foreach ($ids_activo_fijo_session as $id_actfijo_session) {
                if (!$innerLooped)
                    $innerLooped = true;
                if ($id_actfijo_compra == $id_actfijo_session) {
                    unset($ids_activo_fijo_compra[$index]);
                }
            }
            if (!$innerLooped)
                break;
        }

        // Cargar izquierda - derecha.
        foreach (array_values($ids_activo_fijo_compra) as $id) {
            $array_left[] = ['id' => $id, 'text' => $id . ' - ' . ActivoFijo::findOne($id)->nombre];
        }
        foreach ($ids_activo_fijo_session as $id) {
            $array_right[] = ['id' => $id, 'text' => $id . ' - ' . ActivoFijo::findOne($id)->nombre];
        }

        $json_pick_left = json_encode($array_left);
        $json_pick_right = json_encode($array_right);

        if (Yii::$app->request->isPost) {
            $activofijo_ids = [];
            if (array_key_exists('selected', $_POST))
                $activofijo_ids = $_POST['selected'];
            if (sizeof($activofijo_ids) > 0) {
                $ids = [
                    'compra_id' => Yii::$app->session->get($skey_compraId),
                    'ids' => $activofijo_ids,
                ];
                Yii::$app->session->set($skey, $ids);

                $total_costo_adq = 0;
                foreach ($ids['ids'] as $id) {
                    $total_costo_adq += (float)ActivoFijo::findOne(['id' => $id])->costo_adquisicion;
                }
                Yii::$app->session->set("total-costo-adq", ($total_costo_adq * 1.1));
            } else {
                Yii::$app->session->remove($skey);
            }
        }

        Yii::$app->response->format = Response::FORMAT_JSON;
        // Se utiliza el mismo formulario usado para venta de activos fijos porque
        // solamente se necesita pickear para guardar ids y no para modificar
        // los atributos de los activos fijos.
        return $this->renderAjax('_form_select_from_venta', [
            'json_pick_left' => $json_pick_left,
            'json_pick_right' => $json_pick_right,
        ]);
    }

    public function actionGetTotalCostoAdqAjax()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return Yii::$app->session->get('cont_prefijo_fila_plantilla') . '10|' . Yii::$app->session->get('total-costo-adq');
    }

    /** ---------------------- [FIN] ACTIVO FIJO DESDE NOTA CREDITO VENTA ---------------------- */
    private function verificarSession()
    {
        return Yii::$app->session->has('core_empresa_actual') && Yii::$app->session->has('core_empresa_actual_pc');
    }

    public function actionFromFile()
    {
        if (!$this->verificarSession()) {
            FlashMessageHelpsers::createWarningMessage('Falta especificar empresa actual y periodo contable.');
            return $this->redirect(['index']);
        }

        $model = new Archivo();
        $model->reemplazar = 'no';
        $sesion = Yii::$app->session;
        $activos_fijos_creados = [];
        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 10800); // 30 minutos

        if (!Yii::$app->request->isPost)
            $sesion->remove('errors_array');

        if ($model->load(Yii::$app->request->post())) {
            $sesion->remove('errors_array');

            // por el momento, no se va a permitir autocrear tipos, porque hay que relacionarle tambien con cuentas.
            $model->crear_tipo = 'no';
            // cargar el archivo en el atributo 'archivo' del modelo.
            $model->archivo = UploadedFile::getInstance($model, 'archivo');
            $transaction = Yii::$app->getDb()->beginTransaction();
            try {
                /** @var ActivoFijo[] $aFijos */
                // obtener la hoja del excel en $sheetData
                $banNombre = true;
                if($model->tmp_limpiar_grupo == '1'){
                    $actFijosPeriodo = ActivoFijo::find()->where(['empresa_periodo_contable_id' => Yii::$app->session->get('core_empresa_actual_pc')])->all();
                    foreach ($actFijosPeriodo as $actF) {
                        if (isset($actF->asiento))
                            throw new ForbiddenHttpException('Existen uno o más asientos generados por este Activo Fijo. Elimínelos primero.');
                        if (isset($actF->venta))
                            throw new ForbiddenHttpException('Este Activo Fijo está asociada a la Factura de Venta Nº ' . $actF->venta->getNroFacturaCompleto() . '. Elimínelo primero.');
                        if (isset($actF->compra))
                            throw new ForbiddenHttpException('Este Activo Fijo está asociada a la Factura de Compra Nº ' . $actF->compra->nro_factura . '. Elimínelo primero.');
                        foreach ($actF->atributos as $atributo) {
                            if (!$atributo->delete())
                                throw new \Exception("Error eliminando atributos asociados: {$atributo->getErrorSummaryAsString()}");
                        }
                        $actF->delete();
                    }
                }
                $objPHPExcel = IOFactory::load($model->archivo->tempName);
                $sheetData = $objPHPExcel->getActiveSheet()->toArray(null, true, false, true);

                // verificando nombre del archivo importado

                $periodoActual = EmpresaPeriodoContable::findOne(['id' => Yii::$app->session->get('core_empresa_actual_pc')]);

                if (strpos($model->archivo->name, "_")) {
                    $separacionPunto = explode('.', $model->archivo->name);
                    $palabrasList = explode('_', $separacionPunto[0]);

                    if (sizeof($palabrasList) == 3) {
                        if ($palabrasList[0] != "activo-fijo") {
                            //                            var_dump("error prefijo");
                            //                            exit;
                            $banNombre = false;
                        }
                        if (is_numeric($palabrasList[1])) {
                            if ($palabrasList[1] != strval(Yii::$app->session->get('core_empresa_actual'))) {
                                //                                var_dump("error empresa");
                                //                                exit;
                                $banNombre = false;
                            }
                        } else {
                            $banNombre = false;
                        }
                        if (is_numeric($palabrasList[2])) {
                            if ($palabrasList[2] != strval(Yii::$app->session->get('core_empresa_actual_pc'))) {
                                //                                var_dump("error periodo contable");
                                //                                exit;
                                $banNombre = false;
                            }
                        } else {
                            $banNombre = false;
                        }
                    } else {
                        $banNombre = false;
                    }
                    if ($separacionPunto[1] != 'xlsx' and $separacionPunto[1] != 'xlsx') {
                        $banNombre = false;
                    }
                } else {
                    $banNombre = false;
                }

                $map = [
                    'nombre' => 'A',
                    'tipo' => 'B',
                    'costo_adqui' => 'C',
                    'fecha_adqui' => 'D',
                    'valor_fiscal_neto' => 'E',
                    'vida_util_restante' => 'F',
                    'moneda' => 'G',
                    'depreciacion_acumulada' => 'H',
                    'valor_residual' => 'I',
                ];
                $aFijos = [];
                $mensajes = [];
                $hoy = date('Y-m-d');
                // Crear activos fijos
                $cantActFijos = 0;
                if ($banNombre) {
                    foreach ($sheetData as $rownro => $row) {
                        if ($rownro == 1)
                            continue;
                        // Preprocesar fila

                        //Verificacion de la ultima fila vacia para termiinar de procesar las mismas
                        if ($row[$map['nombre']] == "" and $row[$map['tipo']] == "" and $row[$map['costo_adqui']] == "")
                            break; // hasta este punto llega la lista

                        $preproc = self::preprocesarFila($mensajes, $row, $rownro, $map);
                        if (!$preproc)
                            continue;
                        // Verificar tipo de activo fijo.
                        $tipo_id = $row[$map['tipo']];
                        $tipo = ActivoFijoTipo::find()->where(['id' => $tipo_id])->one();
                        if (!isset($tipo)) {
                            $mensajes[] = [
                                'fila' => $rownro,
                                'mensaje' => "Error en la fila {$rownro}: No existe tipo de activo fijo con ID = {$tipo_id}",
                            ];
                            continue;
                        }
                        $aFijo = new ActivoFijo();
                        $aFijo->scenario = 'createFromFile';
                        $aFijo->observacion = "creado el {$hoy} mediante importación desde excel";
                        $aFijo->empresa_id = Yii::$app->session->get('core_empresa_actual');
                        $aFijo->empresa_periodo_contable_id = Yii::$app->session->get('core_empresa_actual_pc');
                        $aFijo->nombre = ucfirst($row[$map['nombre']]);
                        $aFijo->activo_fijo_tipo_id = $tipo->id;
                        $aFijo->costo_adquisicion = $row[$map['costo_adqui']];
                        $aFijo->vida_util_fiscal = $tipo->vida_util;
                        if (!strpos($row[$map['fecha_adqui']], '/')) {
                            $phpDateFromExcel = PHPExcel_Shared_Date::ExcelToPHP($row[$map['fecha_adqui']]);
                            $time = strtotime(date('Y-m-d H:i:s', $phpDateFromExcel));
                            $diffTime = abs(date('Z', $phpDateFromExcel));
                            $aFijo->fecha_adquisicion = date('Y-m-d', $time + $diffTime);
                        } else {
                            $array = explode('/', trim($row[$map['fecha_adqui']]));
                            $aFijo->fecha_adquisicion = implode('-', [$array[2], $array[1], $array[0]]);
                        }
                        $aFijo->vida_util_restante = $row[$map['vida_util_restante']];
                        if ($row[$map['vida_util_restante']] == null || $row[$map['vida_util_restante']] === "") {
                            $array = explode('-', $aFijo->fecha_adquisicion);
                            $pc = $periodoActual->anho;
                            if ($array[0] == $pc || $array[0] == $pc - 1) {
                                $aFijo->vida_util_restante = $tipo->vida_util;
                            } else {
                                $aFijo->vida_util_restante = $aFijo->vida_util_fiscal - ($pc - $array[0] - 1);
                            }
                            $aFijo->vida_util_restante = ($aFijo->vida_util_restante < 0) ? 0 : $aFijo->vida_util_restante;
                        }
                        $aFijo->valor_fiscal_neto = $row[$map['valor_fiscal_neto']];
                        // $aFijo->valor_residual_neto = $row[$map['valor_residual_neto']];
                        $aFijo->depreciacion_acumulada = round($row[$map['depreciacion_acumulada']], 0);
                        $aFijo->valor_residual = round($row[$map['valor_residual']], 0);
                        $aFijo->cuenta_id = $tipo->cuenta_id;
                        $aFijo->cuenta_depreciacion_id = $tipo->cuenta_depreciacion_id;
                        $aFijo->estado = 'activo';
                        if (!$aFijo->validate()) {
                            $msg = [];
                            foreach ($aFijo->errors as $attrib => $error) {
                                $error = is_array($error) ? array_shift($error) : $error;
                                if ($attrib != 'moneda_id')
                                    $msg[] = $error;
                            }
                            if (sizeof($msg) > 0) {
                                $txt = implode(', ', $msg);
                                $mensajes[] = [
                                    'fila' => $rownro,
                                    'mensaje' => "Error validando activo fijo de la fila {$rownro}: {$txt}",
                                ];
                                continue;
                            }
                        }
                        if (!$aFijo->save(false)) {
                            $txt = $aFijo->getErrorSummaryAsString();
                            $mensajes[] = [
                                'fila' => $rownro,
                                'mensaje' => "Error guardando el activo fijo de la fila {$rownro}: {$txt}",
                            ];
                            // throw new \Exception("Error al guardar activo fijo {$aFijo->nombre}: {$aFijo->getErrorSummaryAsString()}");
                        }
                        $cantActFijos++;
                        $aFijos[] = $aFijo;
                    }
                }

                if (sizeof($mensajes)) {
                    $sesion->set('errors_array', $mensajes);
                    throw new \Exception("Hubieron errores.");
                }
                // foreach ($aFijos as $aFijo) {
                //     if (!$aFijo->save(false)) {
                //         throw new \Exception("Error al guardar activo fijo {$aFijo->nombre}: {$aFijo->getErrorSummaryAsString()}");
                //     }
                //     // $aFijo->refresh();
                // }

                $transaction->commit();

                // $cantActFijos = sizeof($aFijos);
                if ($banNombre) {
                    if ($cantActFijos) {
                        $map1 = [0 => 'ha', 1 => 'han'];
                        $map2 = [0 => 'activo fijo', 1 => 'activos fijos'];
                        $index = $cantActFijos > 1;
                        FlashMessageHelpsers::createSuccessMessage("Se {$map1[$index]} creado {$cantActFijos} {$map2[$index]}.");
                    } else {
                        FlashMessageHelpsers::createWarningMessage('El archivo está vacío');
                    }
                } else {
                    FlashMessageHelpsers::createWarningMessage('Cargar el nombre del archivo con el siguiente formato "activo-fijo_<idempresa>_<id periodo contable>.xls/xlsx"');
                }

                $activos_fijos_creados = $aFijos;
            } catch (\Exception $e) {
//                throw $e;
                $transaction->rollBack();
                FlashMessageHelpsers::createWarningMessage('El Activo Fijo no se puede borrar: ' . $e->getMessage() . '.');
            }
        }

        retorno:;
        return $this->render('from-file/_form', ['model' => $model, 'activos_fijos_creados' => $activos_fijos_creados]);
    }

    private function preprocesarFila(&$mensajes, $row, $rownro, $map)
    {
        $sinErrores = true;
        if (!isset($row[$map['nombre']]) || $row[$map['nombre']] === "") {
            $mensajes[] = [
                'fila' => $rownro,
                'mensaje' => "Error en la fila {$rownro}: El nombre del activo fijo está vacío.",
            ];
            $sinErrores = false;
        }
        if (!isset($row[$map['tipo']]) || $row[$map['tipo']] === "") {
            $mensajes[] = [
                'fila' => $rownro,
                'mensaje' => "Error en la fila {$rownro}: El tipo del activo fijo está vacío.",
            ];
            $sinErrores = false;
        }
        if (!isset($row[$map['costo_adqui']]) || $row[$map['costo_adqui']] === "") {
            $mensajes[] = [
                'fila' => $rownro,
                'mensaje' => "Error en la fila {$rownro}: El costo de adquisición del activo fijo está vacío.",
            ];
            $sinErrores = false;
        }
        if (!isset($row[$map['fecha_adqui']]) || $row[$map['fecha_adqui']] === "") {
            $mensajes[] = [
                'fila' => $rownro,
                'mensaje' => "Error en la fila {$rownro}: La fecha de adquisición del activo fijo está vacía.",
            ];
            $sinErrores = false;
        }
        if (!isset($row[$map['valor_fiscal_neto']]) || $row[$map['valor_fiscal_neto']] === "") {
            $mensajes[] = [
                'fila' => $rownro,
                'mensaje' => "Error en la fila {$rownro}: El valor fiscal neto está vacío.",
            ];
            $sinErrores = false;
        }
        if (!isset($row[$map['moneda']]) || $row[$map['moneda']] === "") {
            $mensajes[] = [
                'fila' => $rownro,
                'mensaje' => "Error en la fila {$rownro}: La moneda está vacía.",
            ];
            $sinErrores = false;
        }
        if (!isset($row[$map['valor_residual']]) || $row[$map['valor_residual']] === "") {
            $mensajes[] = [
                'fila' => $rownro,
                'mensaje' => "Error en la fila {$rownro}: El valor residual está vacío.",
            ];
            $sinErrores = false;
        }
        if (!isset($row[$map['depreciacion_acumulada']]) || $row[$map['depreciacion_acumulada']] === "") {
            $mensajes[] = [
                'fila' => $rownro,
                'mensaje' => "Error en la fila {$rownro}: La depreciacion acumulada está vacía.",
            ];
            $sinErrores = false;
        }
        //        if (!isset($row[$map['estado']]) || $row[$map['estado']] == "") {
        //            $mensajes[] = [
        //                'fila' => $rownro,
        //                'mensaje' => "Error en la fila {$rownro}: El estado del activo fijo está vacío.",
        //            ];
        //            $sinErrores = false;
        //        } else {
        //            if (!in_array($row[$map['estado']], ['activo', 'vendido', 'devuelto'])) {
        //                $mensajes[] = [
        //                    'fila' => $rownro,
        //                    'mensaje' => "Error en la fila {$rownro}: Los estados válidos son `activo`, `vendido` y `devuelto`.",
        //                ];
        //            }
        //        }

        return $sinErrores;
    }

    public function actionRevaluacion($operacion = 'revaluo')
    {
        $model = new FormRevaluoAFijo();
        try {
            if (!in_array($operacion, ['revaluo', 'depreciacion']))
                throw new \Exception("Petición no válida");

            $empresa_id = Yii::$app->session->get('core_empresa_actual');
            $periodo_id = Yii::$app->session->get('core_empresa_actual_pc');
            $activosFijos = ActivoFijo::find()
                ->where(['empresa_id' => $empresa_id, 'empresa_periodo_contable_id' => $periodo_id])
                ->andWhere(['IS', "asiento_{$operacion}_id", null]);

            if (!$activosFijos->exists()) {
                throw new \Exception("No se ha encontrado ningún activo fijo sin asiento de $operacion para la empresa y periodo actual.");
            }

            $parametroSistema = ParametroSistema::find()->where(['nombre' => "core_empresa-{$empresa_id}-periodo-{$periodo_id}-criterio_revaluo"]);

            if (!$parametroSistema->exists()) {
                throw new \Exception("Falta definir en el parámetro de empresa el criterio de revaluo (Al mes siguiente/Al ejercicio siguiente).");
            }

            $criterioReval = $parametroSistema->one()->valor;
            $anhoActual = EmpresaPeriodoContable::findOne(['id' => $periodo_id])->anho;
            $mesActual = date("m");
            $coefReval = CoeficienteRevaluo::findOne([
                'periodo' => "{$mesActual}-{$anhoActual}",
                'empresa_id' => Yii::$app->session->get('core_empresa_actual'),
                'periodo_contable_id' => Yii::$app->session->get('core_empresa_actual_pc')
            ]);

            $criterioMesSigte = Empresa::CRITERIO_REVALUO_MES_SIGUIENTE;
            $criterioPerSigte = Empresa::CRITERIO_REVALUO_PERIODO_SIGUIENTE;

            $activosFijos = $activosFijos->all();
            $total = 0;
            $detallesAsiento = [];
            /** @var ActivoFijo $activoFijo */
            foreach ($activosFijos as $index => $activoFijo) {
                if ($activoFijo->cuenta_depreciacion_id == '')
                    throw new \Exception("El activo fijo `{$activoFijo->nombre}` no tiene definido la cuenta para la DEPRECIACIÓN.");

                $tabla_revaluo = $activoFijo->getTablaDepreciacion();
                $anhoAdq = date('Y', strtotime($activoFijo->fecha_adquisicion));
                $anhoX = abs($anhoAdq - $anhoActual) + 1;
                $anhosResantes = max(0, (int)$anhoAdq + (int)$activoFijo->vida_util_fiscal - (int)$anhoActual);
                $coefRevalValue = 1;  # si anho de adq == anho actual y criterio es periodo siguiente, coef = 1.
                # Calculos auxiliares
                if ($anhoAdq == $anhoActual)
                    if ($criterioReval == $criterioMesSigte) {
                        if (!isset($coefReval))
                            throw new Exception("Error en " . __FUNCTION__ . '(): No existe coeficiente de revalúo registrado para este mes del periodo actual.');
                        $mesAdq = date('m', strtotime($activoFijo->fecha_adquisicion));
                        $mesDep = date('m', strtotime('+1 month', strtotime("01-{$mesAdq}-{$anhoActual}")));
                        $coefRevalValue = $coefReval->getCoeficiente($mesDep, CoeficienteRevaluo::EXISTENCIA);
                    } else {  # Se compro este mismo anho actual pero se configura para depreciar al periodo siguiente.
                        Yii::warning($coefRevalValue);
                        # Coeficiente = 1 para que no se revalue.
                        # Depreciaciones acumuladas a cero porque no se deprecia nada.
                        $tabla_revaluo[$anhoX]['deprec_acum_fiscal'] = 0;
                        $tabla_revaluo[$anhoX]['deprec_acum_contab'] = 0;
                    }
                else {
                    if (!isset($coefReval))
                        throw new Exception("Error en " . __FUNCTION__ . '(): No existe coeficiente de revalúo registrado para este mes del periodo actual.');
                    $coefRevalValue = $coefReval->getCoeficiente('01', CoeficienteRevaluo::EXISTENCIA);
                }

                if ($operacion == 'revaluo') {
                    if ($activoFijo->tangible == 'no')  # segun analia, los intangibles se deprecia solamente. y es en 4 anhos. pero que el usuario ponga bien.
                        continue;
                    # Calcular valor fiscal y contable revaluado. Es simplemente producto entre coef. y el valor neto inicial.
                    $activoFijo->valor_fiscal_revaluado = $coefRevalValue * $activoFijo->valor_fiscal_anterior;
                    $activoFijo->valor_contable_revaluado = $coefRevalValue * $activoFijo->valor_contable_anterior;

                    # Generar detalles de asiento
                    $debe = new AsientoDetalle();
                    $debe->cuenta_id = $activoFijo->cuenta_id;
                    $debe->monto_haber = 0;
                    $debe->monto_debe = $activoFijo->valor_fiscal_revaluado + $activoFijo->valor_fiscal_neto;
                    Yii::warning($debe->monto_debe);
                    if (!($anhoAdq == $anhoActual && $criterioReval == $criterioPerSigte))
                        $detallesAsiento[] = $debe;

                    # Acumular total de asiento
                    $total += (float)$debe->monto_debe;

                    # Insertar haber
                    if (sizeof($detallesAsiento) > 0) {
                        $haber = new AsientoDetalle();
                        $haber->monto_haber = $total;
                        $haber->monto_debe = 0;
                        $haber->cuenta_id = '';
                        $detallesAsiento[] = $haber;
                    }
                } else if ($operacion == 'depreciacion') {
                    if ($activoFijo->valor_contable_revaluado == 0)
                        throw new \Exception("No se puede efectuar la depreciación sin antes haber efectuado el revalúo.");

                    # Valor fiscal depreciado
                    $activoFijo->valor_fiscal_depreciado = $activoFijo->valor_fiscal_revaluado - $tabla_revaluo[$anhoX]['deprec_acum_fiscal'];
                    # Valor contable depreciado
                    $cuota_deprec_anual_deduc = ($anhoActual == $anhoAdq && $criterioReval == $criterioMesSigte || $anhoActual != $anhoAdq && $criterioReval == $criterioPerSigte) ? ($activoFijo->valor_fiscal_revaluado / ($anhosResantes + 1)) : 0;
                    $cuota_deprec_anual_nodeduc = ($anhoActual == $anhoAdq && $criterioReval == $criterioMesSigte || $anhoActual != $anhoAdq && $criterioReval == $criterioPerSigte) ? ($activoFijo->valor_contable_revaluado / ($anhosResantes + 1) - $cuota_deprec_anual_deduc) : 0;
                    $activoFijo->valor_contable_depreciado = $activoFijo->valor_contable_revaluado - $cuota_deprec_anual_deduc - $cuota_deprec_anual_nodeduc;

                    # Asientos
                    # Insertar debe
                    if ($index == 0) {
                        $debe = new AsientoDetalle();
                        $debe->monto_debe = 0;
                        $debe->monto_haber = 0;
                        $debe->cuenta_id = '';
                        $detallesAsiento[] = $debe;
                    }

                    $haber = new AsientoDetalle();
                    $haber->cuenta_id = $activoFijo->cuenta_depreciacion_id;
                    $haber->monto_debe = 0;
                    $haber->monto_haber = $tabla_revaluo[$anhoX]['deprec_acum_fiscal'];
                    if (!($anhoAdq == $anhoActual && $criterioReval == $criterioPerSigte))
                        $detallesAsiento[] = $haber;

                    $total += (float)$haber->monto_haber;

                    if ($index == sizeof($activosFijos) - 1) {
                        if (sizeof($detallesAsiento) > 0)
                            $detallesAsiento[0]->monto_debe = $total;
                        /* else
                          unset($detallesAsiento[0]); */
                    }
                }
            }

            # Si no hay ningun asiento preparado,
            # significa que no hay ningun activo fijo revaluado/depreciado
            # entonces retornar al index
            if (sizeof($detallesAsiento) == 0) {
                FlashMessageHelpsers::createInfoMessage("No hay activos fijos a " . ($operacion == 'revaluo' ? 'revaluar' : 'depreciar') . ' para este periodo.');
                return $this->redirect(['index']);
            }

            if ($model->load(Yii::$app->request->post())) {
                $transaction = Yii::$app->db->beginTransaction();
                if ($operacion == 'revaluo') {
                    # Crear asiento
                    $asientoRevaluo = new Asiento();
                    $asientoRevaluo->empresa_id = $empresa_id;
                    $asientoRevaluo->periodo_contable_id = $periodo_id;
                    $asientoRevaluo->fecha = date('Y-m-t');
                    $asientoRevaluo->concepto = "REVALÚO DE ACTIVOS FIJOS " . $anhoActual;
                    $asientoRevaluo->usuario_id = Yii::$app->user->id;
                    $asientoRevaluo->creado = date('Y-m-d H:i:s');
                    $asientoRevaluo->modulo_origen = 'contabilidad'; // TODO: ver si es necesario/correcto/mejorable
                    $asientoRevaluo->monto_debe = $asientoRevaluo->monto_haber = round($total);
                    if (!$asientoRevaluo->save())
                        throw new \Exception("Error generando asiento de {$operacion}: {$asientoRevaluo->getErrorSummaryAsString()}");
                    $asientoRevaluo->refresh();

                    # Guardar detalles de asiento
                    /** @var AsientoDetalle[] $detallesAsiento */
                    foreach ($detallesAsiento as $index => $detalle) {
                        $detalle->asiento_id = $asientoRevaluo->id;
                        $detalle->monto_debe = round($detalle->monto_debe);
                        $detalle->monto_haber = round($detalle->monto_haber);
                        if ($index == sizeof($detallesAsiento) - 1) {
                            $detalle->cuenta_id = $model->cuenta_contrapartida;
                        }
                        if (!$detalle->save())
                            throw new \Exception("Error creando detalle de asiento: {$detalle->getErrorSummaryAsString()}");
                    }

                    # Asociar asiento de revaluo al activo fijo
                    foreach ($activosFijos as $activoFijo) {
                        $activoFijo->asiento_revaluo_id = $asientoRevaluo->id;
                        if (!$activoFijo->save())
                            throw new \Exception("Error actualizando activo fijo {$activoFijo->id} - {$activoFijo->nombre}: {$activoFijo->getErrorSummaryAsString()}");
                    }
                } elseif ($operacion == 'depreciacion') {
                    # Crear asiento
                    $asientoDeprec = new Asiento();
                    $asientoDeprec->empresa_id = $empresa_id;
                    $asientoDeprec->periodo_contable_id = $periodo_id;
                    $asientoDeprec->fecha = date('Y-m-t');
                    $asientoDeprec->concepto = "DEPRECIACION DE ACTIVOS FIJOS " . $anhoActual;
                    $asientoDeprec->usuario_id = Yii::$app->user->id;
                    $asientoDeprec->creado = date('Y-m-d H:i:s');
                    $asientoDeprec->modulo_origen = 'contabilidad'; // TODO: ver si es necesario/correcto/mejorable
                    $asientoDeprec->monto_debe = $asientoDeprec->monto_haber = round($total);
                    if (!$asientoDeprec->save())
                        throw new \Exception("Error generando asiento de {$operacion}: {$asientoDeprec->getErrorSummaryAsString()}");
                    $asientoDeprec->refresh();

                    # Guardar detalles de asiento
                    /** @var AsientoDetalle[] $detallesAsiento */
                    foreach ($detallesAsiento as $index => $detalle) {
                        $detalle->asiento_id = $asientoDeprec->id;
                        $detalle->monto_debe = round($detalle->monto_debe);
                        $detalle->monto_haber = round($detalle->monto_haber);
                        if ($index == 0) {
                            $detalle->cuenta_id = $model->cuenta_contrapartida;
                        }
                        if (!$detalle->save())
                            throw new \Exception("Error creando detalle de asiento: {$detalle->getErrorSummaryAsString()}");
                    }

                    # Asociar asiento de depreciacion al activo fijo
                    foreach ($activosFijos as $activoFijo) {
                        $activoFijo->asiento_depreciacion_id = $asientoDeprec->id;
                        if (!$activoFijo->save())
                            throw new \Exception("Error actualizando activo fijo {$activoFijo->id} - {$activoFijo->nombre}: {$activoFijo->getErrorSummaryAsString()}");
                    }
                }

                $transaction->commit();
                FlashMessageHelpsers::createSuccessMessage("Asiento de $operacion generado correctamente.");
                return $this->redirect(['/contabilidad/asiento/index']);
            }
        } catch (\Exception $exception) {
            //            throw $exception;

            if (isset($transaction) && $transaction instanceof Transaction) {
                $transaction->rollBack();
            }
            FlashMessageHelpsers::createWarningMessage($exception->getMessage());
            return $this->redirect(['index']);
        }

        $view = ($operacion == 'revaluo') ? 'revaluacion/revaluacion' : 'depreciacion/depreciacion';
        return $this->render($view, [
            'model' => $model,
            'activosFijos' => $activosFijos,
            'detallesAsiento' => $detallesAsiento
        ]);
    }

    
    public static function cargarAtributo($id_activo = null){
        
        if($id_activo == null) return null;
        
        // var_dump ($id_activo); exit;
            $sesion = Yii::$app->session;
            $model = ActivoFijo::find()->where(["id"=>$id_activo])->one();
            // $model = self::findModel($id_activo);
            // var_dump($id_activo);exit;
            // var_dump($model->id);exit;
            // Cargar atributos
            /** @var  $_atributos ActivoFijoTipoAtributo[] */
            $atributos = ActivoFijoAtributo::find()->where(['activo_fijo_id' => $id_activo])->all();
            // var_dump($atributos);exit;
            $atribs_re = ActivoFijoAtributo::find()->where(['activo_fijo_id' => $id_activo])->all(); // atributos a renderizar en la vista.
            $_atributos = ActivoFijoTipoAtributo::find()->where(['activo_fijo_tipo_id' => $model->activo_fijo_tipo_id])->all();

            // Agregar atributos nuevos provenientes del tipo, que no estuvieron al crear el activo fijo.
            foreach ($_atributos as $_atributo) {
                $hay = false;
                foreach ($atributos as $atributo) {
                    if ($atributo->atributo == $_atributo->atributo) {
                        $hay = true;
                        break;
                    }
                }
                if (!$hay) {
                    $_newAtributo = new ActivoFijoAtributo();
                    $_newAtributo->loadEmpresaPeriodo();
                    $_newAtributo->atributo = $_atributo->atributo;
                    $_newAtributo->valor = '';
                    $atribs_re[] = $_newAtributo;
                }
            }
            $sesion->set('cont_afijo_atributos', ['tipo_id' => $model->activo_fijo_tipo_id, 'atributos' => $atribs_re]);
            // print_r($sesion->get('cont_afijo_atributos')); 
            //FIN Cargar atributo
    }

}

/**
 * Class Archivo
 * @package backend\modules\contabilidad\controllers
 *
 * @property string $reemplazar
 * @property string $crear_tipo
 */
class Archivo extends Model
{
    /* valores auxiliares */

    public $archivo;
    public $reemplazar;
    public $crear_tipo;
    public $tmp_limpiar_grupo;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['archivo', 'reemplazar', 'crear_tipo'], 'required'],
            [['archivo', 'reemplazar', 'crear_tipo'], 'safe'],
            [['fecha','tmp_limpiar_grupo'], 'safe'],
            [['fecha'], 'required'],
            ['archivo', 'file', 'extensions' => ['xlsx', 'xls'], 'skipOnEmpty' => false, 'maxSize' => 1024 * 1024],
            [['nombreArchivo'], 'match', 'pattern' => '/^\d+-\d{1}_\d{6}']
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'nombre' => 'Archivo a subir',
            'tmp_limpiar_grupo' => 'Limpiar Periodo',
        ];
    }

    public function upload()
    {
        if ($this->validate()) {
            $this->archivo->saveAs('uploads/' . $this->archivo->baseName . '.' . $this->archivo->extension);
            return true;
        } else {
            return false;
        }
    }

//    function calculoVidaRestante($fechaAquisicion, $vidaUtilRestante, $tipo): int
//    {
//        if ($vidaUtilRestante == null || $vidaUtilRestante === "") {
//            $array = explode('-', trim($fechaAquisicion));
//            $pc = Yii::$app->session->get('core_empresa_actual_pc');
//            if ($array[0] == $pc || $array[0] + 1 == $pc) {
//                return $tipo->vida_util;
//            }
//            return ($pc - $array[0] - 1);
//        }
//        return $vidaUtilRestante;
//    }
}
