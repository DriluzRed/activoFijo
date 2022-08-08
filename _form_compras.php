<?php

use backend\modules\contabilidad\models\ActivoFijo;
use backend\modules\contabilidad\models\ActivoFijoAtributo;
use backend\modules\contabilidad\models\Compra;
use backend\modules\contabilidad\models\PlanCuenta;
use common\helpers\FlashMessageHelpsers;
use faryshta\assets\ActiveFormDisableSubmitButtonsAsset;
use kartik\builder\Form;
use kartik\builder\FormGrid;
use kartik\datecontrol\DateControl;
use kartik\form\ActiveForm;
use kartik\select2\Select2;
use yii\db\Query;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\widgets\MaskedInput;
use yii\widgets\Pjax;

/* @var $this yii\web\View */
/* @var $modelos array ActivoFijo */
/* @var $form yii\widgets\ActiveForm */
/* @var $activoFijoAtributo array backend\modules\contabilidad\models\ActivoFijoAtributo */
/* @var $plantilla_id integer */

$forModal = Yii::$app->request->getQueryParam('formodal');
$actvFormOptions = ['id' => 'actfijo-form'];
// var_dump($activoFijoAtributo); exit;
if ($forModal == 'true') {
    ActiveFormDisableSubmitButtonsAsset::register($this);
    $actvFormOptions = [
        'id' => 'actfijo-modal-form',
        'enableAjaxValidation' => true,
        'enableClientScript' => true,
        'enableClientValidation' => true,
        'options' => ['class' => 'disable-submit-buttons'],
    ];
}
?>

<div class="activo-fijo-form">

    <?php $form = ActiveForm::begin($actvFormOptions); ?>

    <?php
    $tiene_factura_compra = isset($model->compra);
    $action_id = Yii::$app->controller->action->id;
    $ultimaClave = 0;
    try {
        // echo "".count($modelos)."";
        foreach($modelos as $clave => $model){
            $moneda_prefixs = [];
            foreach (\backend\models\Moneda::find()->all() as $moneda) {
                $moneda_prefixs["{$moneda->id}"] = "{$moneda->simbolo} ";
            }
            $actFijoTipoList = new Query();
            $actFijoTipoList = $actFijoTipoList
                ->select([
                    'id' => 'MIN(tipo.id)',
                    'text' => 'MIN(tipo.nombre)',
                    'atributos' => "GROUP_CONCAT(CONCAT(atr.atributo, '-', atr.obligatorio))",
                    'vida_util' => 'MIN(tipo.vida_util)',
                    'cantidad_requerida' => 'MIN(tipo.cantidad_requerida)'
                ])
                ->from("cont_activo_fijo_tipo as tipo")
                ->where(['tipo.id' => $model->activo_fijo_tipo_id])
                ->leftJoin("cont_activo_fijo_tipo_atributo as atr", "tipo.id = atr.activo_fijo_tipo_id")
                ->groupBy("tipo.id")
                ->all();

            // //        $actFijoTipoList = ActivoFijoTipo::find()->select(['id' => 'id', 'text' => 'nombre', 'vida_util' => 'vida_util'])->asArray()->all();
            // Yii::$app->session->set('debug', $actFijoTipoList);
            if ($forModal == 'true') {
                $rows = [
                    [
                        'autoGenerateColumns' => false,
                        'columns' => 2,
                        'attributes' => [
                            'nombre' => [
                                'type' => Form::INPUT_RAW,
                                'value' => $form->field($model, "[$clave]nombre")->textInput(['maxlength' => 60]),
                                'options' => ['placeholder ' => 'Nombre']
                            ],

                        ]
                    ],
                    [
                        'autoGenerateColumns' => false,
                        'columns' => 4,
                        'attributes' => [
                            'activo_fijo_tipo_id' => [
                                'type' => Form::INPUT_RAW,
                                'columnOptions' => ['colspan' => '2'],
                                'value' => $form->field($model, "[$clave]activo_fijo_tipo_id")->widget(Select2::class, [
                                    'options' => ['placeholder' => 'Seleccione...'],
                                    'pluginOptions' => [
                                        'allowClear' => true,
                                        'data' => $actFijoTipoList,
                                    ],
                                    'initValueText' => !empty($model->activoFijoTipo->nombre) ? ucfirst($model->activoFijoTipo->nombre) : '',
                                    'pluginEvents' => [
                                        'change' => ""
                                    ]
                                ])
                            ],
                            'vida_util_fiscal' => [
                                'type' => Form::INPUT_RAW,
                                'value' => $form->field($model, "[$clave]vida_util_fiscal")->textInput(['readonly' => true,'value' => !empty($model->vida_util_fiscal) ? ucfirst($model->vida_util_fiscal) : '',]),
                                'columnOptions' => ['colspan' => '1'],
                                'options' => [
                                    'placeholder ' => 'Vida Útil...',
                                    'readonly' => true,
                                ],
                                
                            ],
                            'vida_util_contable' => [
                                'type' => Form::INPUT_RAW,
                                'value' => $form->field($model, "[$clave]vida_util_contable")->textInput(['value' => !empty($model->vida_util_contable) ? ucfirst($model->vida_util_contable) : '',]),
                                'columnOptions' => ['colspan' => '1'],
                                'options' => [
                                    'placeholder ' => 'Vida Útil Contable...',
                                ],
                            ],
                        ],
                    ],
                    [
                        'autoGenerateColumns' => false,
                        'columns' => 4,
                        'attributes' => [
                            'valor_fiscal_neto' => [
                                'type' => Form::INPUT_RAW,
                                'value' => $form->field($model, "[$clave]valor_fiscal_neto")->textInput(['type' => 'number','value' => !empty($model->valor_fiscal_neto) ? ucfirst($model->valor_fiscal_neto) : '']),
                                'options' => ['placeholder ' => 'Valor Fiscal Neto...']
                            ],
                            'cantidad' => [
                                'type' => Form::INPUT_RAW,
                                'value' => $form->field($model, "[$clave]cantidad")->textInput(['type' => 'number','value' => !empty($model->cantidad) ? ucfirst($model->cantidad) : '',]),
                            ],
                            'fecha_adquisicion' => [
                                'type' => Form::INPUT_TEXT,
                                'label' => false,
                                'options' => ['style' => ['display' => 'none']]
                            ],
                        ]
                    ],
                ];
            }


            echo FormGrid::widget([
                'model' => $model,
                'form' => $form,
                'autoGenerateColumns' => true,
                'rows' => $rows
            ]);

            //ATRIBUTOS DINAMICOS?>
            <div class="activo-fijo-tipo-atributo">
            <table id="tabla-atributos" class="table table-condensed table-responsive">
          
            <?php
            // var_dump($activoFijoAtributo);exit;
            // print_r($model->activoFijoTipo->tipoAtributos);exit;
            // existing detalles fields
            foreach($model->activoFijoTipo->tipoAtributos as $tipoAtributo){
                $existe = false;
                if(isset($activoFijoAtributo[$clave])){
                    foreach($activoFijoAtributo[$clave] as $_atributo) {
                        // print_r($tipoAtributo);exit;
                        if(isset($_atributo->atributo) && $_atributo->atributo == $tipoAtributo->atributo){
                        $existe = true;
                        ?>
                            <tr>
                                <td>
                                    <?= $form->field($_atributo, "[$clave][$tipoAtributo->id]atributo")->textInput(['readonly' => true])->label(false) ?>
                                </td>
                                <td>
                                    <?= $form->field($_atributo, "[$clave][$tipoAtributo->id]valor")->textInput([])->label(false) ?>
                                </td>
                            </tr>
                        <?php
                        break;
                        }
                    }
                }

                // print_r($tipoAtributo);exit;
                if($existe == false){
                    // print_r($tipoAtributo);exit;
                    $nuevoAtributo =  new ActivoFijoAtributo();
                    $nuevoAtributo->atributo = $tipoAtributo->atributo;
                    $nuevoAtributo->activo_fijo_id = $model->id;
                    $nuevoAtributo->empresa_id = $model->empresa_id;
                    $nuevoAtributo->activo_fijo_tipo_id = $tipoAtributo->id;
                   

                    ?>
                    <tr>
                        <td>
                            <?= $form->field($nuevoAtributo, "[$clave][$tipoAtributo->id]atributo")->textInput(['readonly' => true])->label(false) ?>
                        </td>
                        <td>
                            <?= $form->field($nuevoAtributo, "[$clave][$tipoAtributo->id]valor")->textInput([])->label(false) ?>
                        </td>
                    </tr>
                    <?php
                    
                }
            }
            if(count($modelos)>1){
            ?>
                <tr>
                    <td></td>
                    <td style="align-content: right;">
                        <?=Html::button('<span class="glyphicon glyphicon-trash"></span>', [
                    'id' => 'btn_delete_current',
                    'style' => "display: yes;",
                    'title' => 'Borrar actual',
                    'class' => 'btn btn-warning pull-right',
                    'plantilla_id' => $plantilla_id,
                    'activo_nro' => $clave,
                    'onclick' => 'clickEliminarBtn(this);',
                    'data-url' => Url::to(['manejar-desde-factura-compra'])
                ]);?>
                    </td>
                </tr>
            <?php
            }
            ?>
            
        </table>
        </div>
            <hr/>
            <?php
            $ultimaClave = $clave;
        }
    } catch (Exception $e) {
        throw $e;
        FlashMessageHelpsers::createWarningMessage($e->getMessage());
    }

    $boton_guardar_class = "";
    if (Yii::$app->controller->action->id == "create") {
        $boton_guardar_class = 'btn btn-success';
    } else {
        $boton_guardar_class = 'btn btn-primary';
    }

    echo Html::beginTag('div', ['class' => 'form-group text-right btn-toolbar']);
    $submitBtnOptions = ['data' => ['disabled-text' => 'Guardando...'], 'class' => $boton_guardar_class];
    echo Html::submitButton('Guardar',['class' => $boton_guardar_class, 'id'=>'guardar' ,'onclick' => 'true']);
    echo Html::submitButton('<span class="glyphicon glyphicon-plus"></span>', ['class' => 'btn btn-success pull-right' ,'style' => "display: yes;", 'id'=>'nuevo' ,'plantilla_id' => $plantilla_id]);
    // if (!in_array(Yii::$app->controller->action->id, ['create', 'update', 'delete', 'index'])) {
    //     echo Html::button('<span class="glyphicon glyphicon-plus"></span>', [
    //         'id' => 'btn_new',
    //         'style' => "display: yes;",
    //         'title' => 'Agregar Activo Fijo',
    //         'class' => 'btn btn-success pull-right',
    //         'data-url' => Url::to(['manejar-desde-factura-compra']),
    //         'plantilla_id' => $plantilla_id,
    //         'onclick' => 'clickNewBtn(this);',
    //     ]);
    // }
    echo Html::tag('br/');
    echo Html::endTag('div');
    ?>

    <?php ActiveForm::end(); ?>

    <?php
    $costo_adq_desde_factura = Yii::$app->session->get('costo_adq_desde_factura', null); // para calcular precio unitario si la cantidad es mayor a 1
    $moneda_prefixs = Json::encode($moneda_prefixs);
    $infoColor = \backend\helpers\HtmlHelpers::InfoColorHex(true);
    $url_loadAtributos = Json::htmlEncode(\Yii::t('app', Url::to(['load-atributos'])));
    $url_manejarDesdeCompra = Json::htmlEncode(\Yii::t('app', Url::to(['manejar-desde-factura-compra'])));
    
    $script = <<<JS
    
var costo_adq_desde_factura = "{$costo_adq_desde_factura}";
var buttonClick = '';
document.getElementById('nuevo').onclick = function(){
    buttonClick = 'nuevo';
}
document.getElementById('guardar').onclick = function(){
    buttonClick = 'guardar';
}
// obtener la id del formulario y establecer el manejador de eventos
$("#actfijo-modal-form").on("beforeSubmit", function (e) {
    var form = $(this);
    // console.log($("button[type=submit][clicked=true]"));
    // console.log(document.getElementById("nuevo").click);
    // console.log($('nuevo').attr('plantilla_id'));
    // console.log(form.attr("action") + "&submit=true");
    if(buttonClick == 'guardar'){
        // alert(form.attr("action"));
        $.get(
        form.attr("action") + "&submit=true",
        form.serialize()
        )
        .done(function (result) {

            form.parent().html(result.message);
            $.pjax.reload({container: "#flash_message_id", async: false});
            $("#modal").modal("hide");
            $("modal-body").html("");
            // $('#btn-hidden-simulator').click();
        });
        return false;
    }else if(buttonClick == 'nuevo'){
        // alert("nuevo");
        // alert(form.attr("action"));
        original = form.attr("action");
        $.get(
        form.attr("action") + "&btn=nuevo",
        form.serialize()
        )
        .done(function (data) {
            $('.modal-body', modal).html("");
            $('.modal-body', modal).html(data);
            $("#actfijo-modal-form").attr('action', original);
            $('#modal').trigger('change');
        });
        return false;
    }
    else{
        // alert("nuevo");
        // alert(form.attr("action"));
        $.get(
        form.attr("action"),
        form.serialize()
        )
        .done(function (data) {
            $('.modal-body', modal).html("");
            $('.modal-body', modal).html(data);
            $('#modal').trigger('change');
        });
        return false;
    }
}).on("submit", function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    return false;
});


// function clickNewBtn(boton) {
//     let fecha_factura = $('#activofijo-fecha_adquisicion').val();
//     let url = $(boton).attr('data-url') + '&formodal=' + true + '&fecha_factura=' + fecha_factura+ '&btn=nuevo' + "&plantilla_id="+$(boton).attr('plantilla_id'); 
// //     console.log($("#actfijo-modal-form"));
//     $.ajax({
//         url: url,
//         type: 'get',
//         data: {},
//         success: function (data) {
//             $('.modal-body', modal).html("");
//             $('.modal-body', modal).html(data);
//             $('#modal').trigger('change');
//         }
//     })
// };

function clickEliminarBtn(boton) {
    let fecha_factura = $('#activofijo-fecha_adquisicion').val();
    let url = $(boton).attr('data-url') + '&formodal=' + true + '&fecha_factura=' + fecha_factura+ '&btn=eliminar' + "&plantilla_id="+$(boton).attr('plantilla_id')+ "&activo_nro="+$(boton).attr('activo_nro'); 
    //  $.post(
    //     form.attr("action") + "&submit=true",
    //     form.serialize()
    // )
    original = ($(boton).attr('data-url') + '&formodal=' + true + '&fecha_factura=' + fecha_factura + "&plantilla_id="+$(boton).attr('plantilla_id'));
    $.ajax({
        url: url,
        type: 'post',
        data: {
            formodal: true,
            fecha_factura: fecha_factura,
            btn: 'eliminar',
            plantilla_id: $(boton).attr('plantilla_id'),
            activo_nro: $(boton).attr('activo_nro'),
        },
        success: function (data) {
            $('.modal-body', modal).html("");
            $('.modal-body', modal).html(data);
            $("#actfijo-modal-form").attr('action', original);
            $('#modal').trigger('change');
        }
    })
};



// $('#activofijo-costo_adquisicion').keyup(function () {
//     let valor = $(this).val().replace(' Gs', '').replace(/\./g, '').replace(/\,/g, '.');
//     if (valor !== "") {
//         valor = parseFloat(valor);
//     } else {
//         valor = 0.0;
//     }
//     console.log(valor);
//     $('#activofijo-valor_fiscal_neto').val(valor);
// });

$('#modal').on('shown.bs.modal', function () {
    $("input[name=\"ActivoFijo[{$ultimaClave}][nombre]\"]").focus(); // siempre se pone el focus en el primer input
});
JS;

    $this->registerJs($script);
    ?>
</div>