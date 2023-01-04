<?php

namespace ffyb\operaciones\inscripcion_cursos;

use SIU\Chulupi\interfaz\pagelet;
use SIU\Chulupi\kernel;
use siu\errores\error_guarani;
use siu\guarani;
use siu\modelo\datos\catalogo;
use siu\modelo\entidades\parametro;

class pagelet_info_curso extends \siu\operaciones\inscripcion_cursos\pagelet_info_curso
{

    const INICIAL           				= 'inicial';
    const CURSO_SELECCIONADO				= 'cur_sel';
    const ERROR_CURSO_SELECCIONADO		    = 'error_cur_sel';
    const REINSCRIPCION						= 'reinsc';

    protected function add_mensajes_js()
    {
        $this->add_var_js('msg_baja', kernel::traductor()->trans('inscripcion_cursos.mensaje_baja_curso'));
        $this->add_var_js('msg_confirmar_baja', kernel::traductor()->trans('inscripcion_cursos.mensaje_confirmar_baja'));
        $this->add_var_js('msg_baja_exitosa', kernel::traductor()->trans('inscripcion_cursos.baja_exitosa'));
		$this->add_var_js('msg_confirmar_inscripcion', kernel::traductor()->trans('inscripcion_cursos.inscribirse'));
        $this->add_var_js('msg_inscripcion_exitosa', kernel::traductor()->trans('inscripcion_cursos.mensaje_alta_curso'));
        $this->add_var_js('aceptar', ucfirst(kernel::traductor()->trans('inscripcion_cursos.aceptar')));
        $this->add_var_js('cancelar', ucfirst(kernel::traductor()->trans('inscripcion_cursos.cancelar')));

        $this->add_var_js('msg_titulo_enviar', kernel::traductor()->trans('titulo_enviar_comprobante'));
        $this->add_var_js('msg_enviar', kernel::traductor()->trans('enviar_comprobante_cursada', array(
            '%1%' => kernel::persona()->get_mail()
        )));
        $this->add_var_js('msg_enviar_boton', kernel::traductor()->trans('enviar_por_mail'));

        $this->add_var_js('msg_falta_mail', kernel::traductor()->trans('mail_no_cargado', array(
            '%1%' => kernel::vinculador()->crear('configuracion')
        )));
        $this->add_var_js('envio_mail_exitoso', kernel::traductor()->trans('envio_mail_exitoso'));
        $this->add_var_js('url_baja', kernel::vinculador()->crear(kernel::ruteador()->get_id_operacion(), 'baja'));
        $this->add_var_js('url_verificar_solicitud_consumo_externo', kernel::vinculador()->crear(kernel::ruteador()->get_id_operacion(), 'verificar_sol_cons_ext'));
        $this->add_var_js('url_obtener_forma_pago_consumo_externo', kernel::vinculador()->crear(kernel::ruteador()->get_id_operacion(), 'obtener_forma_pago'));
        $this->add_var_js('url_es_comision_cobrable', kernel::vinculador()->crear(kernel::ruteador()->get_id_operacion(), 'es_comision_cobrable'));
        $this->add_var_js('url_existe_inscripcion', kernel::vinculador()->crear(kernel::ruteador()->get_id_operacion(), 'existe_inscripcion'));
        $this->add_var_js('si', \comunes::si);
        $this->add_var_js('no', \comunes::no);
        $this->add_var_js('modo_devolucion_no_corresponde', \cobro::SUSCRIPCIONES_NOVEDADES_MODO_DEVOLUCION_NO_CORRESPONDE);
        $this->add_var_js('estado_consumo_pagado', \cobro::ESTADO_CONSUMO_PAGADO);
        $this->add_var_js('modos_devolucion_validos', \cobro::get_modos_devolucion_suscripcion_novedad());
        $this->add_var_js('estado_consumo_pendiente', \cobro::ESTADO_CONSUMO_PENDIENTE);
        $this->add_var_js('forma_cobro_credito', \cobro::CONSUMOS_EXTERNOS_FORMA_COBRO_CREDITO);
        $this->add_var_js('forma_cobro_ac', \cobro::CONSUMOS_EXTERNOS_FORMA_COBRO_AC);
        $this->add_var_js('modo_devolucion_credito', \cobro::SUSCRIPCIONES_NOVEDADES_MODO_DEVOLUCION_CREDITO);
        $this->add_var_js('modo_devolucion_ac', \cobro::SUSCRIPCIONES_NOVEDADES_MODO_DEVOLUCION_AGENTE_COBRANZA);
        $this->add_var_js('modo_devolucion_pago_invalido', kernel::traductor()->trans('inscripcion_cursos.modo_devolucion_pago_invalido'));

        $this->add_mensaje_js('titulo', kernel::traductor()->trans('nombre_sistema')." - ".kernel::traductor()->trans('tit_inscripcion_cursos'));

        $this->add_mensaje_js('error_no_existe_comprobante_inscripcion', kernel::traductor()->trans('inscripcion_cursos.error_no_existe_comprobante_inscripcion'));
        $this->add_mensaje_js('error_no_existe_inscripcion', kernel::traductor()->trans('inscripcion_cursos.error_no_existe_inscripcion'));
    }

    protected function add_datos_generales_pagelet(){
        $this->data['si'] = \comunes::si;
        $this->data['no'] = \comunes::no;
        $this->data['catalogo_id'] = catalogo::id;
        $this->data['inscripcion_pendiente'] = \inscripcion_cursada::estado_pendiente;
        $this->data['inscripcion_aceptada'] = \inscripcion_cursada::estado_aceptada;
        $this->data['modos_devolucion_pago'] = array(
            \cobro::SUSCRIPCIONES_NOVEDADES_MODO_DEVOLUCION_CREDITO => kernel::traductor()->trans('inscripcion_cursos.modo_devolucion_pago_credito_universidad'),
            \cobro::SUSCRIPCIONES_NOVEDADES_MODO_DEVOLUCION_AGENTE_COBRANZA => kernel::traductor()->trans('inscripcion_cursos.modo_devolucion_pago_agente_cobranza'),
        );
    }

    protected function prepare_inicial()
    {

        $parametros_cursada = array();
        $parametros_cursada['persona']  = kernel::persona()->get_id();
        $parametros_cursada['estado']   = "AP";  // Aceptadas y Pendientes
        $parametros_cursada['vigentes'] = "S";

		// Tipo de propuesta Curso.
		$propuesta_tipo_curso = kernel::db()->quote(\propuesta::tipo_curso);
        
        // Obtengo la propuesta seleccionada de sesion.
        $propuesta_seleccionada = null;
		$actividad = null;
        if(kernel::sesion()->esta_seteada('__propuesta')) $propuesta_seleccionada = kernel::sesion()->get('__propuesta');
		
		// Solo recupera inscripciones de propuestas de tipo curso.
		$inscripciones = guarani::persona()->cursadas()->get_inscripciones($propuesta_seleccionada, $actividad, $propuesta_tipo_curso);

        $fecha_actual = new \DateTime('today');

        $comisiones = array();
        foreach($inscripciones as $key => $inscripcion) {

            $fecha_fin_dictado = \DateTime::createFromFormat("!Y-m-d", $inscripcion['fecha_fin_dictado']);

            //Si termino el dictado de la comisi�n borro la inscripci�n de la misma
            if($fecha_fin_dictado < $fecha_actual){
                unset($inscripciones[$key]);
                continue;
            }

            $comisiones[] =  $inscripcion['comision'];
        }

        if (!empty($comisiones)) {
            $info_comisiones = $this->modelo()->info__cupo_comisiones($comisiones);
        }

        foreach ($inscripciones as $key => $inscripcion) {
            foreach ($info_comisiones as $info) {
                if ($inscripcion['comision'] == $info['comision']) {
                    $var = $info;
                    if (isset($info['cupo']) &&  $info['cupo'] > 0) {
                        $var['porcentaje_cupo'] = round (($info['cant_inscriptos'] <=0 ? 0 : $info['cant_inscriptos']) / $info['cupo'] * 100,0);
                        $var['mostrar_barra_cupo'] = true;
                        $var['cupo_definido'] = true;
                    } else {
                        $var['cupo_definido'] = false;
                        $var['mostrar_barra_cupo'] = false;

                    }

                    $inscripciones[$key]['URL_COMP'] = $this->get_vinculo_comp($inscripcion[catalogo::id]);
                    $inscripciones[$key]['URL_IMP_COMP'] = $this->get_vinculo_imp_comp($inscripcion[catalogo::id]);
                    $inscripciones[$key]['URL_MAIL_COMP'] = $this->get_vinculo_mail_comp($inscripcion[catalogo::id]);
                    $inscripciones[$key]['URL_BAJA'] = kernel::vinculador()->crear(kernel::ruteador()->get_id_operacion(), 'baja_inicial');

                    $inscripciones[$key]['pago_pendiente'] = $inscripcion['pago_pendiente'];
                    $inscripciones[$key]['comision_cobrable'] = $inscripcion['comision_cobrable'];
                    $inscripciones[$key]['estado_inscripcion'] = $inscripcion['estado'];

                    $inscripciones[$key]['info_cupo'][] = $var;

                }
            }
        }

        $this->data['inscripciones'] = $inscripciones;
        $this->data['csrf']	= $this->generar_csrf();

    }

    protected function prepare_curso_seleccionado()
    {
        $curso = $this->get_curso();
        //Traigo las comisiones del curso seleccionado
        $comisiones = $this->modelo()->info__comisiones_curso_usando_co_comisiones($curso['propuesta'], $curso['plan'], $curso['plan_version'], $curso['elemento']);
        //$comisiones = $this->modelo()->info__comisiones_curso($curso['propuesta'], $curso['plan'], $curso['plan_version'], $curso['elemento']);
        //Traigo las inscripciones a comisiones del curso seleccionado
        $comisiones_inscriptas = $this->modelo()->info__inscripciones($curso['elemento']);
        $id_comision_no_ordenar = $this->get_comision_ignorar();

        $this->data['comisiones'] = $comisiones;
        $this->data['existe_inscripcion'] = false;
		$this->data['dias_semana'] = $this->modelo()->info__dias_semana();
		$this->data['ubicaciones'] = $this->modelo()->info__ubicaciones();
		
        foreach ($comisiones as $key => $comision) {
            //Si esta inscripto a la comision
            if ($this->esta_inscripto_a_comision($comisiones_inscriptas, $comision['comision'])) {
                $inscripcion_a_comision = $this->get_comision_inscripta_info($comisiones_inscriptas, $comision['comision']);

                $this->data['existe_inscripcion'] = true;
                $agregar_al_principio = $comision[catalogo::id] != $id_comision_no_ordenar;
                $this->agregar_comision_inscripta($key, $inscripcion_a_comision, $curso['elemento'], $comision, $agregar_al_principio);
            } else {//Si no esta inscripto
                $this->agregar_comision_no_inscripta($key, $curso['elemento'], $comision);
            }
			
        }

		$this->data['sedes_comisiones'] = [];
		foreach ($this->data['comisiones'] as $key => $comision) {			
			$this->data['sedes_comisiones'][$comision['ubicacion']]['comisiones'][] = $comision;
			$this->data['sedes_comisiones'][$comision['ubicacion']]['ubicacion_nombre'] = $comision['ubicacion_nombre'];
			
		}		
        $this->data['mensajes']	= $this->get_mensajes();

        $this->data['curso_codigo'] = $this->get_curso_codigo();
        $this->data['curso_nombre'] = $this->get_curso_nombre();

        $this->data['responsables_academicas'] = $this->get_curso_ra();

        $plan_activo = kernel::sesion()->get('__plan');
        // sedes
        $this->data['sedes'] = guarani::lista_ubicaciones();
        $this->data['csrf']	= $this->generar_csrf();

        $this->add_var_js('existe_inscripcion', $this->data['existe_inscripcion']);

        $this->add_var_js('msg_confirma_desea_inscribirse_a_cursar', kernel::traductor()->trans('800CUR_confirmar_alta_insc', array(
            '%1%' => $this->get_curso_codigo(),
            '%2%' => $this->get_curso_nombre()
        )));

        $this->data['codigo_inscripcion_cant_digitos'] = \comision::CODIGO_INSCRIPCION_CANT_DIGITOS;
        $this->add_var_js('codigo_inscripcion_cant_digitos', \comision::CODIGO_INSCRIPCION_CANT_DIGITOS);

        $this->add_var_js('codigo_inscripcion_regexp', \comision::CODIGO_INSCRIPCION_REGEXP);

    }

}